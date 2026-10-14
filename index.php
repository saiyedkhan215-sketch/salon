<?php
// ==============================================================================
// CONFIGURATION (Update these for InfinityFree)
// ==============================================================================
define('DB_HOST', 'localhost');      // e.g., sql123.epizy.com
define('DB_NAME', 'barber_shop');    // e.g., epiz_123456_barber_shop
define('DB_USER', 'root');           // e.g., epiz_123456
define('DB_PASS', '');               // e.g., Your vPanel Password
define('TIMEZONE', 'Asia/Riyadh');

date_default_timezone_set(TIMEZONE);
session_start();

// Database Connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("Database connection failed. Please check your credentials in index.php.");
}

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper Functions
function sanitize($input) { return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8'); }
function json_response($status, $data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['status' => $status], $data));
    exit;
}

function getSettings($pdo) {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM business_settings");
    $settings = [];
    while ($row = $stmt->fetch()) { $settings[$row['setting_key']] = $row['setting_value']; }
    return $settings;
}

$settings = getSettings($pdo);

// ==============================================================================
// AJAX API ENDPOINTS
// ==============================================================================
if (isset($_GET['action'])) {
    // CSRF Validation for POST state changes
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_SERVER['HTTP_X_CSRF_TOKEN']) || $_SERVER['HTTP_X_CSRF_TOKEN'] !== $_SESSION['csrf_token'])) {
        json_response('error', ['message' => 'Invalid CSRF token.']);
    }

    if ($_GET['action'] === 'get_slots') {
        $date = $_GET['date'] ?? '';
        $service_id = (int)($_GET['service_id'] ?? 0);
        if (!$date || !$service_id || strtotime($date) < strtotime(date('Y-m-d'))) { json_response('error', ['message' => 'Invalid date.']); }

        $stmt = $pdo->prepare("SELECT duration_minutes, is_active FROM services WHERE id = ?");
        $stmt->execute([$service_id]);
        $service = $stmt->fetch();
        if (!$service || !$service['is_active']) { json_response('error', ['message' => 'Service unavailable.']); }

        $dayOfWeek = date('N', strtotime($date)); // 1-7
        $stmt = $pdo->prepare("SELECT start_time, end_time, is_off FROM working_hours WHERE day_of_week = ?");
        $stmt->execute([$dayOfWeek]);
        $wh = $stmt->fetch();
        
        if (!$wh || $wh['is_off']) { json_response('success', ['slots' => []]); }

        $stmt = $pdo->prepare("SELECT start_time, end_time FROM blocked_times WHERE block_date = ?");
        $stmt->execute([$date]);
        $blocks = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT start_time, end_time FROM appointments WHERE appointment_date = ? AND appointment_status NOT IN ('cancelled', 'rejected')");
        $stmt->execute([$date]);
        $appointments = $stmt->fetchAll();

        $duration = $service['duration_minutes'];
        $interval = (int)($settings['booking_interval_minutes'] ?? 30);
        $slots = [];
        
        $current_time = strtotime($date . ' ' . $wh['start_time']);
        $end_time = strtotime($date . ' ' . $wh['end_time']);
        $now = time();

        while ($current_time + ($duration * 60) <= $end_time) {
            $slot_end = $current_time + ($duration * 60);
            $isValid = true;

            if ($current_time < $now + 1800) { $isValid = false; } // At least 30 mins advance

            if ($isValid) {
                foreach ($blocks as $block) {
                    $b_start = strtotime($date . ' ' . $block['start_time']);
                    $b_end = strtotime($date . ' ' . $block['end_time']);
                    if (max($current_time, $b_start) < min($slot_end, $b_end)) { $isValid = false; break; }
                }
            }

            if ($isValid) {
                foreach ($appointments as $app) {
                    $a_start = strtotime($date . ' ' . $app['start_time']);
                    $a_end = strtotime($date . ' ' . $app['end_time']);
                    if (max($current_time, $a_start) < min($slot_end, $a_end)) { $isValid = false; break; }
                }
            }

            if ($isValid) {
                $slots[] = date('H:i', $current_time);
            }
            $current_time += ($interval * 60);
        }
        json_response('success', ['slots' => $slots]);
    }

    if ($_GET['action'] === 'book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $service_id = (int)$input['service_id'];
        $date = sanitize($input['date']);
        $time = sanitize($input['time']);
        $name = sanitize($input['name']);
        $phone = sanitize($input['phone']);
        $email = sanitize($input['email']);
        $notes = sanitize($input['notes']);

        if (!$service_id || !$date || !$time || !$name || !$phone) {
            json_response('error', ['message' => 'Please fill all required fields.']);
        }

        try {
            $pdo->beginTransaction();

            // Row lock for the date to prevent concurrent double booking
            $stmt = $pdo->prepare("INSERT IGNORE INTO scheduling_locks (lock_date) VALUES (?)");
            $stmt->execute([$date]);
            $stmt = $pdo->prepare("SELECT lock_date FROM scheduling_locks WHERE lock_date = ? FOR UPDATE");
            $stmt->execute([$date]);

            $stmt = $pdo->prepare("SELECT name, price, duration_minutes, is_active FROM services WHERE id = ?");
            $stmt->execute([$service_id]);
            $service = $stmt->fetch();
            if (!$service || !$service['is_active']) { throw new Exception("Service not available."); }
            
            $start_stamp = strtotime($date . ' ' . $time);
            $end_stamp = $start_stamp + ($service['duration_minutes'] * 60);
            $end_time_str = date('H:i:s', $end_stamp);
            $start_time_str = date('H:i:s', $start_stamp);

            // Re-check overlaps inside transaction
            $stmt = $pdo->prepare("SELECT id FROM appointments WHERE appointment_date = ? AND appointment_status NOT IN ('cancelled', 'rejected') AND ((start_time < ? AND end_time > ?))");
            $stmt->execute([$date, $end_time_str, $start_time_str]);
            if ($stmt->rowCount() > 0) { throw new Exception("Time slot is no longer available."); }

            // Insert/Get Customer
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
            $stmt->execute([$phone]);
            $customer = $stmt->fetch();
            if ($customer) {
                $customer_id = $customer['id'];
                $stmt = $pdo->prepare("UPDATE customers SET full_name=?, email=? WHERE id=?");
                $stmt->execute([$name, $email, $customer_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO customers (full_name, phone, email) VALUES (?, ?, ?)");
                $stmt->execute([$name, $phone, $email]);
                $customer_id = $pdo->lastInsertId();
            }

            // Generate Booking Ref
            $ref = 'BS-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

            $stmt = $pdo->prepare("INSERT INTO appointments (booking_reference, customer_id, service_id, service_name_snapshot, price_snapshot, duration_minutes_snapshot, appointment_date, start_time, end_time, customer_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$ref, $customer_id, $service_id, $service['name'], $service['price'], $service['duration_minutes'], $date, $start_time_str, $end_time_str, $notes]);
            
            $pdo->commit();
            json_response('success', ['reference' => $ref]);

        } catch (Exception $e) {
            $pdo->rollBack();
            json_response('error', ['message' => $e->getMessage()]);
        }
    }

    if ($_GET['action'] === 'lookup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $ref = sanitize($input['reference']);
        $phone = sanitize($input['phone']);
        
        $stmt = $pdo->prepare("
            SELECT a.booking_reference, a.appointment_date, a.start_time, a.service_name_snapshot, a.price_snapshot, a.appointment_status, a.payment_status 
            FROM appointments a 
            JOIN customers c ON a.customer_id = c.id 
            WHERE a.booking_reference = ? AND c.phone = ?
        ");
        $stmt->execute([$ref, $phone]);
        $appt = $stmt->fetch();

        if ($appt) { json_response('success', ['appointment' => $appt]); } 
        else { json_response('error', ['message' => 'No booking found matching these details.']); }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['business_name']) ?> | Premium Barbershop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root { --primary-gold: #d4af37; --dark-bg: #111; --card-bg: #1a1a1a; --text-light: #e0e0e0; }
        body { background-color: var(--dark-bg); color: var(--text-light); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .navbar { background-color: #000; border-bottom: 2px solid var(--primary-gold); }
        .navbar-brand, .nav-link { color: #fff !important; }
        .nav-link:hover { color: var(--primary-gold) !important; }
        .hero { background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('https://images.unsplash.com/photo-1585747860715-2ba37e788b70?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80') center/cover; padding: 120px 0; border-bottom: 1px solid #333; }
        .text-gold { color: var(--primary-gold) !important; }
        .btn-gold { background-color: var(--primary-gold); color: #000; font-weight: bold; border: none; }
        .btn-gold:hover { background-color: #b5952f; color: #000; }
        .card { background-color: var(--card-bg); border: 1px solid #333; color: var(--text-light); }
        .service-card { transition: transform 0.3s, border-color 0.3s; cursor: pointer; }
        .service-card:hover, .service-card.selected { transform: translateY(-5px); border-color: var(--primary-gold); }
        .time-slot { background: #222; border: 1px solid #444; color: #fff; cursor: pointer; transition: 0.2s; padding: 10px; text-align: center; border-radius: 5px; }
        .time-slot:hover, .time-slot.selected { background: var(--primary-gold); color: #000; border-color: var(--primary-gold); }
        .form-control, .form-select { background-color: #222; border: 1px solid #444; color: #fff; }
        .form-control:focus, .form-select:focus { background-color: #2b2b2b; color: #fff; border-color: var(--primary-gold); box-shadow: none; }
        .step-indicator { opacity: 0.5; transition: 0.3s; }
        .step-indicator.active { opacity: 1; color: var(--primary-gold); font-weight: bold; }
        footer { background-color: #0a0a0a; border-top: 1px solid #222; }
        #booking-wizard .step-content { display: none; }
        #booking-wizard .step-content.active { display: block; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container">
        <a class="navbar-brand text-gold fw-bold fs-3" href="#"><i class="bi bi-scissors"></i> <?= htmlspecialchars($settings['business_name']) ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <i class="bi bi-list text-white fs-2"></i>
        </button>
        <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
            <ul class="navbar-nav align-items-center">
                <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="#services">Services</a></li>
                <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
                <li class="nav-item"><a class="nav-link" href="#lookup">My Booking</a></li>
                <li class="nav-item ms-lg-3"><a class="btn btn-gold px-4 rounded-pill" href="#book">Book Appointment</a></li>
            </ul>
        </div>
    </div>
</nav>

<section id="home" class="hero text-center text-lg-start">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <h1 class="display-3 fw-bold text-white mb-3">Your Style. <span class="text-gold">Your Signature.</span></h1>
                <p class="lead mb-4">Professional grooming, flexible scheduling, and uncompromising quality. Experience the finest barbering in Riyadh.</p>
                <div class="mb-4 d-flex align-items-center justify-content-center justify-content-lg-start gap-3">
                    <span class="fs-5 fw-bold text-white"><i class="bi bi-star-fill text-gold"></i> 4.4/5</span>
                    <span class="text-secondary">based on 43 Google Maps reviews</span>
                </div>
                <a href="#book" class="btn btn-gold btn-lg me-3 mb-2 px-4 rounded-pill">Book an Appointment</a>
                <a href="#services" class="btn btn-outline-light btn-lg mb-2 px-4 rounded-pill">Explore Services</a>
            </div>
        </div>
    </div>
</section>

<section id="services" class="py-5">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="text-gold fw-bold">Our Services</h2>
            <p class="text-secondary">Select a service to start booking your appointment.</p>
        </div>
        <div class="row g-4">
            <?php
            $stmt = $pdo->query("SELECT * FROM services WHERE is_active = 1 ORDER BY price DESC");
            while ($service = $stmt->fetch()):
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-4 service-card" onclick="startBooking(<?= $service['id'] ?>, '<?= sanitize($service['name']) ?>', <?= $service['price'] ?>)">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h4 class="fw-bold mb-0"><?= sanitize($service['name']) ?></h4>
                        <span class="badge bg-dark border border-secondary p-2 fs-6"><?= $service['price'] ?> <?= htmlspecialchars($settings['currency']) ?></span>
                    </div>
                    <p class="text-secondary small mb-4 flex-grow-1"><?= sanitize($service['description']) ?></p>
                    <div class="d-flex justify-content-between align-items-center mt-auto">
                        <span class="text-muted"><i class="bi bi-clock text-gold"></i> <?= $service['duration_minutes'] ?> min</span>
                        <button class="btn btn-sm btn-outline-light rounded-pill px-3">Book</button>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
</section>

<section id="book" class="py-5" style="background-color: #0c0c0c;">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card p-4 p-md-5 rounded-4 shadow-lg">
                    <h3 class="text-gold fw-bold text-center mb-4">Book Your Appointment</h3>
                    
                    <div class="d-flex justify-content-between mb-5 border-bottom border-dark pb-3 text-center small">
                        <div class="step-indicator active w-25" id="ind-1">1. Service</div>
                        <div class="step-indicator w-25" id="ind-2">2. Date & Time</div>
                        <div class="step-indicator w-25" id="ind-3">3. Details</div>
                        <div class="step-indicator w-25" id="ind-4">4. Confirm</div>
                    </div>

                    <div id="booking-wizard">
                        <!-- Step 1 -->
                        <div class="step-content active" id="step-1">
                            <h5 class="mb-3">Selected Service</h5>
                            <div id="selected-service-display" class="alert alert-dark border border-secondary mb-4 text-center">
                                Please select a service from the section above to continue.
                            </div>
                            <div class="text-end">
                                <button type="button" class="btn btn-gold px-4" id="btn-next-1" disabled onclick="nextStep(2)">Continue</button>
                            </div>
                        </div>

                        <!-- Step 2 -->
                        <div class="step-content" id="step-2">
                            <h5 class="mb-3">Choose Date & Time</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label text-secondary">Date</label>
                                    <input type="date" id="book-date" class="form-control" min="<?= date('Y-m-d') ?>" onchange="fetchSlots()">
                                </div>
                            </div>
                            <div id="slots-container" class="row g-2 mb-4"></div>
                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-light px-4" onclick="nextStep(1)">Back</button>
                                <button type="button" class="btn btn-gold px-4" id="btn-next-2" disabled onclick="nextStep(3)">Continue</button>
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div class="step-content" id="step-3">
                            <h5 class="mb-3">Your Information</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-12">
                                    <input type="text" id="book-name" class="form-control" placeholder="Full Name *" required>
                                </div>
                                <div class="col-md-6">
                                    <input type="tel" id="book-phone" class="form-control" placeholder="Phone (e.g. +966...) *" value="+966" required>
                                </div>
                                <div class="col-md-6">
                                    <input type="email" id="book-email" class="form-control" placeholder="Email Address (Optional)">
                                </div>
                                <div class="col-12">
                                    <textarea id="book-notes" class="form-control" rows="2" placeholder="Any special notes?"></textarea>
                                </div>
                                <div class="col-12 mt-3">
                                    <div class="form-check text-secondary small">
                                        <input class="form-check-input bg-dark border-secondary" type="checkbox" id="book-agree" onchange="validateStep3()">
                                        <label class="form-check-label" for="book-agree">
                                            I agree to pay at the shop. (Offline Payment Policy)
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-light px-4" onclick="nextStep(2)">Back</button>
                                <button type="button" class="btn btn-gold px-4" id="btn-next-3" disabled onclick="submitBooking()">Confirm Booking</button>
                            </div>
                        </div>

                        <!-- Step 4 -->
                        <div class="step-content text-center py-4" id="step-4">
                            <div id="booking-loading" class="d-none">
                                <div class="spinner-border text-gold mb-3" role="status"></div>
                                <h4>Processing Booking...</h4>
                            </div>
                            <div id="booking-success" class="d-none">
                                <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                                <h3 class="text-white mt-3 mb-1">Booking Request Received</h3>
                                <p class="text-secondary mb-4">Status: Pending Confirmation</p>
                                
                                <div class="card bg-dark border-secondary mb-4 p-3 text-start mx-auto" style="max-width: 400px;">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">Reference:</span>
                                        <strong id="confirm-ref" class="text-gold fs-5"></strong>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">Service:</span>
                                        <span id="confirm-service"></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">Date & Time:</span>
                                        <span id="confirm-datetime"></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">Price:</span>
                                        <span id="confirm-price"></span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Payment:</span>
                                        <span>Pay at the shop (Unpaid)</span>
                                    </div>
                                </div>
                                <p class="small text-secondary">Please save your reference number to look up your booking later.</p>
                                <button class="btn btn-outline-light mt-3" onclick="location.reload()">Book Another</button>
                            </div>
                            <div id="booking-error" class="d-none">
                                <i class="bi bi-x-circle-fill text-danger" style="font-size: 4rem;"></i>
                                <h4 class="mt-3 text-white">Booking Failed</h4>
                                <p id="booking-error-msg" class="text-danger"></p>
                                <button class="btn btn-outline-light mt-3" onclick="nextStep(3)">Try Again</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="lookup" class="py-5">
    <div class="container py-4">
        <div class="row align-items-center">
            <div class="col-lg-5 mb-5 mb-lg-0">
                <h2 class="text-gold fw-bold mb-4">Manage My Booking</h2>
                <p class="text-secondary mb-4">Check the status of your appointment or view details. Enter your booking reference and the phone number you used.</p>
                <div class="card p-4">
                    <input type="text" id="lookup-ref" class="form-control mb-3" placeholder="Booking Ref (e.g. BS-XXXXXX)">
                    <input type="tel" id="lookup-phone" class="form-control mb-3" placeholder="Phone Number">
                    <button class="btn btn-gold w-100" onclick="lookupBooking()">Find Booking</button>
                </div>
            </div>
            <div class="col-lg-6 offset-lg-1">
                <div id="lookup-result" class="card p-4 d-none">
                    <!-- Loaded via JS -->
                </div>
            </div>
        </div>
    </div>
</section>

<footer class="py-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <h4 class="text-gold mb-3 fw-bold"><i class="bi bi-scissors"></i> <?= htmlspecialchars($settings['business_name']) ?></h4>
                <p class="text-secondary small">Premium grooming, modern styling, and an unforgettable barbershop experience.</p>
                <p class="mb-1"><i class="bi bi-geo-alt text-gold me-2"></i> <?= htmlspecialchars($settings['address']) ?></p>
                <p class="mb-1"><i class="bi bi-telephone text-gold me-2"></i> <a href="tel:<?= str_replace(' ', '', $settings['phone']) ?>" class="text-white text-decoration-none"><?= htmlspecialchars($settings['phone']) ?></a></p>
                <p><i class="bi bi-clock text-gold me-2"></i> Open 24 Hours</p>
            </div>
            <div class="col-lg-4">
                <h5 class="text-white mb-3">Quick Links</h5>
                <ul class="list-unstyled text-secondary small line-height-lg">
                    <li class="mb-2"><a href="#home" class="text-secondary text-decoration-none hover-gold">Home</a></li>
                    <li class="mb-2"><a href="#services" class="text-secondary text-decoration-none">Services</a></li>
                    <li class="mb-2"><a href="#book" class="text-secondary text-decoration-none">Book Appointment</a></li>
                    <li class="mb-2"><a href="#lookup" class="text-secondary text-decoration-none">My Booking</a></li>
                </ul>
            </div>
            <div class="col-lg-4">
                <h5 class="text-white mb-3">Location</h5>
                <div class="card bg-dark border-secondary p-3 text-center h-100 justify-content-center">
                    <i class="bi bi-map fs-1 text-gold mb-2"></i>
                    <p class="small text-secondary mb-3">JR9Q+C2, As Sulay, Riyadh, Saudi Arabia</p>
                    <a href="<?= htmlspecialchars($settings['maps_url']) ?>" target="_blank" class="btn btn-outline-light btn-sm rounded-pill">Get Directions</a>
                </div>
            </div>
        </div>
        <div class="text-center text-secondary small border-top border-dark mt-5 pt-4">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($settings['business_name']) ?>. All Rights Reserved. Offline Payment only.
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const csrfToken = '<?= $_SESSION['csrf_token'] ?>';
    let bookingData = { service_id: null, service_name: '', price: 0, date: '', time: '' };

    function startBooking(id, name, price) {
        bookingData.service_id = id;
        bookingData.service_name = name;
        bookingData.price = price;
        document.getElementById('selected-service-display').innerHTML = `<strong>${name}</strong> <br> ${price} SAR`;
        document.getElementById('selected-service-display').classList.replace('alert-dark', 'alert-success');
        document.getElementById('btn-next-1').disabled = false;
        document.getElementById('book').scrollIntoView({ behavior: 'smooth' });
    }

    function nextStep(step) {
        document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.step-indicator').forEach(el => el.classList.remove('active'));
        document.getElementById('step-' + step).classList.add('active');
        for(let i=1; i<=step; i++) { document.getElementById('ind-' + i).classList.add('active'); }
    }

    async function fetchSlots() {
        const date = document.getElementById('book-date').value;
        const container = document.getElementById('slots-container');
        bookingData.date = date;
        bookingData.time = '';
        document.getElementById('btn-next-2').disabled = true;
        
        if(!date) return;
        container.innerHTML = '<div class="text-center w-100 text-secondary"><div class="spinner-border spinner-border-sm"></div> Checking availability...</div>';
        
        try {
            const res = await fetch(`?action=get_slots&date=${date}&service_id=${bookingData.service_id}`);
            const data = await res.json();
            if(data.status === 'success' && data.slots.length > 0) {
                container.innerHTML = data.slots.map(t => `<div class="col-4 col-sm-3"><div class="time-slot" onclick="selectTime('${t}', this)">${t}</div></div>`).join('');
            } else {
                container.innerHTML = '<div class="alert alert-dark text-center w-100 border-secondary">No slots available on this date.</div>';
            }
        } catch(e) {
            container.innerHTML = '<div class="alert alert-danger w-100 text-center">Error fetching availability.</div>';
        }
    }

    function selectTime(time, el) {
        document.querySelectorAll('.time-slot').forEach(n => n.classList.remove('selected'));
        el.classList.add('selected');
        bookingData.time = time;
        document.getElementById('btn-next-2').disabled = false;
    }

    function validateStep3() {
        const name = document.getElementById('book-name').value.trim();
        const phone = document.getElementById('book-phone').value.trim();
        const agree = document.getElementById('book-agree').checked;
        document.getElementById('btn-next-3').disabled = !(name.length > 2 && phone.length > 5 && agree);
    }

    document.getElementById('book-name').addEventListener('input', validateStep3);
    document.getElementById('book-phone').addEventListener('input', validateStep3);

    async function submitBooking() {
        nextStep(4);
        document.getElementById('booking-loading').classList.remove('d-none');
        document.getElementById('booking-success').classList.add('d-none');
        document.getElementById('booking-error').classList.add('d-none');

        const payload = {
            ...bookingData,
            name: document.getElementById('book-name').value.trim(),
            phone: document.getElementById('book-phone').value.trim(),
            email: document.getElementById('book-email').value.trim(),
            notes: document.getElementById('book-notes').value.trim()
        };

        try {
            const res = await fetch('?action=book', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            
            document.getElementById('booking-loading').classList.add('d-none');
            if(data.status === 'success') {
                document.getElementById('booking-success').classList.remove('d-none');
                document.getElementById('confirm-ref').innerText = data.reference;
                document.getElementById('confirm-service').innerText = bookingData.service_name;
                document.getElementById('confirm-datetime').innerText = bookingData.date + ' at ' + bookingData.time;
                document.getElementById('confirm-price').innerText = bookingData.price + ' SAR';
            } else {
                throw new Error(data.message || 'Booking failed');
            }
        } catch(e) {
            document.getElementById('booking-loading').classList.add('d-none');
            document.getElementById('booking-error').classList.remove('d-none');
            document.getElementById('booking-error-msg').innerText = e.message;
        }
    }

    async function lookupBooking() {
        const ref = document.getElementById('lookup-ref').value.trim();
        const phone = document.getElementById('lookup-phone').value.trim();
        const resDiv = document.getElementById('lookup-result');
        
        if(!ref || !phone) return alert('Please enter both reference and phone number.');
        
        resDiv.classList.remove('d-none');
        resDiv.innerHTML = '<div class="text-center"><div class="spinner-border text-gold"></div></div>';
        
        try {
            const res = await fetch('?action=lookup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ reference: ref, phone: phone })
            });
            const data = await res.json();
            if(data.status === 'success') {
                const a = data.appointment;
                const statusBadge = a.appointment_status === 'confirmed' ? 'bg-success' : (a.appointment_status === 'cancelled' ? 'bg-danger' : 'bg-warning text-dark');
                const payBadge = a.payment_status === 'paid' ? 'bg-success' : 'bg-secondary';
                
                resDiv.innerHTML = `
                    <h5 class="text-gold border-bottom border-dark pb-2 mb-3">Booking: ${a.booking_reference}</h5>
                    <div class="mb-2"><strong>Service:</strong> ${a.service_name_snapshot}</div>
                    <div class="mb-2"><strong>Date & Time:</strong> ${a.appointment_date} at ${a.start_time.substring(0,5)}</div>
                    <div class="mb-2"><strong>Price:</strong> ${a.price_snapshot} SAR</div>
                    <div class="mb-2"><strong>Booking Status:</strong> <span class="badge ${statusBadge}">${a.appointment_status.toUpperCase()}</span></div>
                    <div class="mb-2"><strong>Payment Status:</strong> <span class="badge ${payBadge}">${a.payment_status.toUpperCase()}</span></div>
                `;
            } else {
                resDiv.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
            }
        } catch(e) {
            resDiv.innerHTML = `<div class="alert alert-danger">System error. Try again.</div>`;
        }
    }
</script>
</body>
</html>
