<?php
// ==============================================================================
// CONFIGURATION & SETUP
// ==============================================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'barber_shop');
define('DB_USER', 'root');
define('DB_PASS', '');
define('TIMEZONE', 'Asia/Riyadh');
// INSTRUCTIONS: Change this to a secure random string for first-time installation!
// Visit: admin.php?setup=YOUR_SECURE_SECRET
define('SETUP_SECRET', 'CHANGE_ME_SECURE_SETUP_123');

date_default_timezone_set(TIMEZONE);
session_start();

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("Admin DB Connection failed.");
}

function sanitize($input) { return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['admin_csrf'])) { $_SESSION['admin_csrf'] = bin2hex(random_bytes(32)); }

// ==============================================================================
// ONE-TIME SETUP
// ==============================================================================
if (isset($_GET['setup']) && $_GET['setup'] === SETUP_SECRET) {
    $stmt = $pdo->query("SELECT count(*) as c FROM admins");
    if ($stmt->fetch()['c'] > 0) { die("Setup already completed."); }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = $_POST['username'];
        $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash) VALUES (?, ?)");
        $stmt->execute([$user, $pass]);
        die("Admin created! Delete SETUP_SECRET from code for safety, then <a href='admin.php'>Login</a>");
    }
    echo '<form method="POST"><h2>Admin Setup</h2>User: <input name="username"><br>Pass: <input type="password" name="password"><br><button>Create Admin</button></form>';
    exit;
}

// ==============================================================================
// AUTHENTICATION
// ==============================================================================
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT id, password_hash FROM admins WHERE username = ? AND is_active = 1");
    $stmt->execute([$user]);
    $admin = $stmt->fetch();
    
    if ($admin && password_verify($pass, $admin['password_hash'])) {
        $_SESSION['admin_id'] = $admin['id'];
        session_regenerate_id(true);
        header("Location: admin.php");
        exit;
    } else {
        $error = "Invalid credentials.";
    }
}

if (!isset($_SESSION['admin_id'])) {
?>
<!DOCTYPE html>
<html>
<head><title>Admin Login</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-dark d-flex align-items-center vh-100">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card bg-dark text-white border-secondary shadow">
                    <div class="card-body p-4">
                        <h3 class="text-center mb-4 text-warning">Admin Login</h3>
                        <?php if($error): ?><div class="alert alert-danger py-2"><?= $error ?></div><?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="login" value="1">
                            <div class="mb-3"><input type="text" name="username" class="form-control bg-dark text-white border-secondary" placeholder="Username" required></div>
                            <div class="mb-3"><input type="password" name="password" class="form-control bg-dark text-white border-secondary" placeholder="Password" required></div>
                            <button class="btn btn-warning w-100">Login</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

// ==============================================================================
// POST ACTIONS (Requires CSRF)
// ==============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['login'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) { die("CSRF Validation Failed."); }
    $action = $_POST['action'] ?? '';
    $msg = '';

    if ($action === 'update_status') {
        $id = (int)$_POST['id'];
        $status = $_POST['status'];
        $stmt = $pdo->prepare("UPDATE appointments SET appointment_status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        $msg = "Status updated.";
    } 
    elseif ($action === 'record_payment') {
        $id = (int)$_POST['id'];
        $amount = (float)$_POST['amount'];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT payment_status FROM appointments WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            if ($stmt->fetch()['payment_status'] === 'paid') throw new Exception("Already paid.");
            
            $stmt = $pdo->prepare("INSERT INTO payments (appointment_id, amount, received_at, recorded_by_admin_id) VALUES (?, ?, NOW(), ?)");
            $stmt->execute([$id, $amount, $_SESSION['admin_id']]);
            
            $stmt = $pdo->prepare("UPDATE appointments SET payment_status = 'paid' WHERE id = ?");
            $stmt->execute([$id]);
            $pdo->commit();
            $msg = "Payment recorded successfully.";
        } catch (Exception $e) { $pdo->rollBack(); $msg = "Error: " . $e->getMessage(); }
    }
    elseif ($action === 'save_service') {
        $id = (int)$_POST['id'];
        $name = sanitize($_POST['name']);
        $desc = sanitize($_POST['description']);
        $price = (float)$_POST['price'];
        $dur = (int)$_POST['duration'];
        $active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($id) {
            $stmt = $pdo->prepare("UPDATE services SET name=?, description=?, price=?, duration_minutes=?, is_active=? WHERE id=?");
            $stmt->execute([$name, $desc, $price, $dur, $active, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO services (name, description, price, duration_minutes, is_active) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $desc, $price, $dur, $active]);
        }
        $msg = "Service saved.";
    }
    $_SESSION['msg'] = $msg;
    header("Location: admin.php?view=" . ($_GET['view'] ?? 'dashboard'));
    exit;
}

$view = $_GET['view'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
    <style>
        body { background-color: #f4f6f9; }
        .sidebar { min-height: 100vh; background: #212529; color: #fff; width: 250px; position: fixed; }
        .sidebar a { color: #adb5bd; text-decoration: none; padding: 12px 20px; display: block; border-left: 3px solid transparent; }
        .sidebar a:hover, .sidebar a.active { background: #343a40; color: #fff; border-left-color: #ffc107; }
        .main-content { margin-left: 250px; padding: 20px; width: calc(100% - 250px); }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075); border: none; }
        @media (max-width: 768px) { .sidebar { width: 100%; height: auto; position: relative; } .main-content { margin-left: 0; width: 100%; } }
    </style>
</head>
<body>

<div class="d-flex flex-column flex-md-row">
    <!-- Sidebar -->
    <nav class="sidebar py-3">
        <h4 class="text-center text-warning fw-bold mb-4">Barbershop OS</h4>
        <a href="?view=dashboard" class="<?= $view=='dashboard'?'active':'' ?>"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
        <a href="?view=appointments" class="<?= $view=='appointments'?'active':'' ?>"><i class="bi bi-calendar-check me-2"></i> Appointments</a>
        <a href="?view=calendar" class="<?= $view=='calendar'?'active':'' ?>"><i class="bi bi-calendar-month me-2"></i> Calendar</a>
        <a href="?view=services" class="<?= $view=='services'?'active':'' ?>"><i class="bi bi-list-check me-2"></i> Services</a>
        <a href="?view=customers" class="<?= $view=='customers'?'active':'' ?>"><i class="bi bi-people me-2"></i> Customers</a>
        <a href="?logout=1" class="text-danger mt-5"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <?php if(isset($_SESSION['msg']) && $_SESSION['msg']): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= $_SESSION['msg'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['msg']); ?>
        <?php endif; ?>

        <?php if($view === 'dashboard'): 
            $stats = $pdo->query("SELECT 
                COUNT(*) as total, 
                SUM(CASE WHEN appointment_date = CURDATE() THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN appointment_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN payment_status = 'unpaid' AND appointment_status NOT IN ('cancelled','rejected') THEN 1 ELSE 0 END) as unpaid
                FROM appointments")->fetch();
            
            $revenue = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments")->fetch()['total'];
        ?>
            <h2 class="mb-4">Dashboard</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-3"><div class="card bg-primary text-white p-3"><h5>Today's Bookings</h5><h2><?= $stats['today'] ?></h2></div></div>
                <div class="col-md-3"><div class="card bg-warning text-dark p-3"><h5>Pending Requests</h5><h2><?= $stats['pending'] ?></h2></div></div>
                <div class="col-md-3"><div class="card bg-danger text-white p-3"><h5>Unpaid Bookings</h5><h2><?= $stats['unpaid'] ?></h2></div></div>
                <div class="col-md-3"><div class="card bg-success text-white p-3"><h5>Total Collected</h5><h2><?= number_format($revenue,2) ?> SAR</h2></div></div>
            </div>
            
            <div class="card p-3">
                <h5 class="mb-3">Today's Schedule</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light"><tr><th>Time</th><th>Customer</th><th>Service</th><th>Status</th><th>Payment</th></tr></thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("SELECT a.*, c.full_name, c.phone FROM appointments a JOIN customers c ON a.customer_id = c.id WHERE a.appointment_date = CURDATE() ORDER BY a.start_time ASC");
                            while($row = $stmt->fetch()):
                            ?>
                            <tr>
                                <td><?= substr($row['start_time'],0,5) ?></td>
                                <td><?= sanitize($row['full_name']) ?><br><small><?= sanitize($row['phone']) ?></small></td>
                                <td><?= sanitize($row['service_name_snapshot']) ?></td>
                                <td><span class="badge bg-<?= $row['appointment_status']=='confirmed'?'success':($row['appointment_status']=='pending'?'warning text-dark':'secondary') ?>"><?= strtoupper($row['appointment_status']) ?></span></td>
                                <td><span class="badge bg-<?= $row['payment_status']=='paid'?'success':'danger' ?>"><?= strtoupper($row['payment_status']) ?></span></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if($view === 'appointments'): ?>
            <h2 class="mb-4">Manage Appointments</h2>
            <div class="card p-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle text-nowrap">
                        <thead class="table-light">
                            <tr><th>Ref</th><th>Date & Time</th><th>Customer</th><th>Service</th><th>Price</th><th>Status</th><th>Payment</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("SELECT a.*, c.full_name, c.phone FROM appointments a JOIN customers c ON a.customer_id = c.id ORDER BY a.appointment_date DESC, a.start_time DESC LIMIT 100");
                            while($row = $stmt->fetch()):
                            ?>
                            <tr>
                                <td><small class="text-muted"><?= $row['booking_reference'] ?></small></td>
                                <td><?= $row['appointment_date'] ?><br><small><?= substr($row['start_time'],0,5) ?></small></td>
                                <td><?= sanitize($row['full_name']) ?><br><small><?= sanitize($row['phone']) ?></small></td>
                                <td><?= sanitize($row['service_name_snapshot']) ?></td>
                                <td><?= $row['price_snapshot'] ?> SAR</td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['admin_csrf'] ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                            <option value="pending" <?= $row['appointment_status']=='pending'?'selected':'' ?>>Pending</option>
                                            <option value="confirmed" <?= $row['appointment_status']=='confirmed'?'selected':'' ?>>Confirmed</option>
                                            <option value="completed" <?= $row['appointment_status']=='completed'?'selected':'' ?>>Completed</option>
                                            <option value="cancelled" <?= $row['appointment_status']=='cancelled'?'selected':'' ?>>Cancelled</option>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <?php if($row['payment_status'] === 'unpaid' && !in_array($row['appointment_status'], ['cancelled','rejected'])): ?>
                                        <button class="btn btn-sm btn-outline-success" onclick="document.getElementById('pay_id').value='<?= $row['id'] ?>'; document.getElementById('pay_amount').value='<?= $row['price_snapshot'] ?>';" data-bs-toggle="modal" data-bs-target="#paymentModal">Collect</button>
                                    <?php else: ?>
                                        <span class="badge bg-<?= $row['payment_status']=='paid'?'success':'secondary' ?>"><?= strtoupper($row['payment_status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-light border" onclick="alert('Notes: <?= sanitize($row['customer_notes'] ?? 'None') ?>')"><i class="bi bi-info-circle"></i></button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Payment Modal -->
            <div class="modal fade" id="paymentModal" tabindex="-1">
                <div class="modal-dialog">
                    <form method="POST" class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">Record Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['admin_csrf'] ?>">
                            <input type="hidden" name="action" value="record_payment">
                            <input type="hidden" name="id" id="pay_id">
                            <p>Record an in-person offline payment for this booking.</p>
                            <label class="form-label">Amount (SAR)</label>
                            <input type="number" step="0.01" name="amount" id="pay_amount" class="form-control" required readonly>
                        </div>
                        <div class="modal-footer"><button class="btn btn-success">Confirm Payment</button></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if($view === 'services'): ?>
            <h2 class="mb-4">Manage Services</h2>
            <div class="card p-3">
                <button class="btn btn-primary mb-3" style="width:fit-content" data-bs-toggle="modal" data-bs-target="#serviceModal" onclick="document.getElementById('srv_form').reset(); document.getElementById('srv_id').value='';">Add Service</button>
                <table class="table align-middle">
                    <thead class="table-light"><tr><th>Name</th><th>Price</th><th>Duration</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php
                        $stmt = $pdo->query("SELECT * FROM services");
                        while($row = $stmt->fetch()):
                        ?>
                        <tr>
                            <td><?= sanitize($row['name']) ?></td>
                            <td><?= $row['price'] ?> SAR</td>
                            <td><?= $row['duration_minutes'] ?> min</td>
                            <td><span class="badge bg-<?= $row['is_active']?'success':'secondary' ?>"><?= $row['is_active']?'Active':'Inactive' ?></span></td>
                            <td>
                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#serviceModal" 
                                    onclick="
                                        document.getElementById('srv_id').value='<?= $row['id'] ?>';
                                        document.getElementById('srv_name').value='<?= addslashes($row['name']) ?>';
                                        document.getElementById('srv_desc').value='<?= addslashes($row['description']) ?>';
                                        document.getElementById('srv_price').value='<?= $row['price'] ?>';
                                        document.getElementById('srv_dur').value='<?= $row['duration_minutes'] ?>';
                                        document.getElementById('srv_act').checked=<?= $row['is_active'] ? 'true' : 'false' ?>;
                                    ">Edit</button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Service Modal -->
            <div class="modal fade" id="serviceModal" tabindex="-1">
                <div class="modal-dialog">
                    <form method="POST" id="srv_form" class="modal-content">
                        <div class="modal-header"><h5 class="modal-title">Service Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['admin_csrf'] ?>">
                            <input type="hidden" name="action" value="save_service">
                            <input type="hidden" name="id" id="srv_id">
                            <div class="mb-2"><label>Name</label><input type="text" name="name" id="srv_name" class="form-control" required></div>
                            <div class="mb-2"><label>Description</label><textarea name="description" id="srv_desc" class="form-control" rows="2"></textarea></div>
                            <div class="mb-2"><label>Price (SAR)</label><input type="number" step="0.01" name="price" id="srv_price" class="form-control" required></div>
                            <div class="mb-2"><label>Duration (Minutes)</label><input type="number" name="duration" id="srv_dur" class="form-control" required></div>
                            <div class="form-check"><input type="checkbox" name="is_active" id="srv_act" class="form-check-input" value="1" checked><label>Active (Bookable)</label></div>
                        </div>
                        <div class="modal-footer"><button class="btn btn-primary">Save Service</button></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if($view === 'calendar'): 
            // Feed data for calendar
            $evs = [];
            $stmt = $pdo->query("SELECT appointment_date, start_time, end_time, service_name_snapshot, appointment_status, c.full_name FROM appointments a JOIN customers c ON a.customer_id = c.id WHERE appointment_status NOT IN ('cancelled', 'rejected')");
            while($r = $stmt->fetch()) {
                $evs[] = [
                    'title' => $r['start_time'] . ' ' . $r['full_name'],
                    'start' => $r['appointment_date'] . 'T' . $r['start_time'],
                    'end' => $r['appointment_date'] . 'T' . $r['end_time'],
                    'color' => $r['appointment_status'] === 'confirmed' ? '#198754' : '#ffc107'
                ];
            }
        ?>
            <h2 class="mb-4">Schedule</h2>
            <div class="card p-3"><div id="calendar"></div></div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var calendarEl = document.getElementById('calendar');
                    var calendar = new FullCalendar.Calendar(calendarEl, {
                        initialView: 'timeGridWeek',
                        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
                        events: <?= json_encode($evs) ?>,
                        slotMinTime: '00:00:00', slotMaxTime: '24:00:00', allDaySlot: false
                    });
                    calendar.render();
                });
            </script>
        <?php endif; ?>

        <?php if($view === 'customers'): ?>
            <h2 class="mb-4">Customers</h2>
            <div class="card p-3">
                <table class="table align-middle">
                    <thead class="table-light"><tr><th>Name</th><th>Phone</th><th>Total Appts</th><th>Registered</th></tr></thead>
                    <tbody>
                        <?php
                        $stmt = $pdo->query("SELECT c.*, COUNT(a.id) as appt_count FROM customers c LEFT JOIN appointments a ON c.id = a.customer_id GROUP BY c.id ORDER BY c.created_at DESC");
                        while($row = $stmt->fetch()):
                        ?>
                        <tr>
                            <td><?= sanitize($row['full_name']) ?></td>
                            <td><?= sanitize($row['phone']) ?></td>
                            <td><span class="badge bg-secondary"><?= $row['appt_count'] ?></span></td>
                            <td><?= substr($row['created_at'],0,10) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
