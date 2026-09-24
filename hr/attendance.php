<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('attendance');

$currentUser = current_user();
$currentUserId = (int) $currentUser['id'];
$isManager = in_array($currentUser['role'], ['super_admin', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'punch_in') {
        // Latitude/longitude come from the browser's own Geolocation API,
        // captured into these hidden fields just before submit — see the
        // script at the bottom of this page. Location is mandatory: the
        // form only submits once a real position has been obtained, and
        // this check backs that up server-side (attendance_location_is_valid()
        // in includes/hr.php) so attendance can never be created without it,
        // even via a direct POST that bypasses the frontend.
        $lat = ($_POST['latitude'] ?? '') !== '' ? (float) $_POST['latitude'] : null;
        $lng = ($_POST['longitude'] ?? '') !== '' ? (float) $_POST['longitude'] : null;
        if (!attendance_location_is_valid($lat, $lng)) {
            flash_set('error', 'Location access is required to mark attendance. Please allow location access and try again.');
            redirect('hr/attendance.php');
        }
        if (!punch_in($currentUserId, $lat, $lng)) {
            flash_set('error', "You've already punched in today.");
        } else {
            flash_set('status', 'Punched in at ' . date('H:i') . '.');
        }
        redirect('hr/attendance.php');
    }

    if ($action === 'punch_out') {
        $lat = ($_POST['latitude'] ?? '') !== '' ? (float) $_POST['latitude'] : null;
        $lng = ($_POST['longitude'] ?? '') !== '' ? (float) $_POST['longitude'] : null;
        if (!attendance_location_is_valid($lat, $lng)) {
            flash_set('error', 'Location access is required to mark attendance. Please allow location access and try again.');
            redirect('hr/attendance.php');
        }
        punch_out($currentUserId, $lat, $lng);
        flash_set('status', 'Punched out at ' . date('H:i') . '.');
        redirect('hr/attendance.php');
    }

    if ($action === 'mark_attendance' && $isManager) {
        mark_attendance(
            (int) $_POST['user_id'],
            $_POST['attendance_date'] ?? date('Y-m-d'),
            $_POST['status'] ?? 'present',
            sanitize_string($_POST['notes'] ?? '') ?: null
        );
        flash_set('status', 'Attendance updated.');
        redirect('hr/attendance.php?date=' . ($_POST['attendance_date'] ?? date('Y-m-d')));
    }
}

$viewDate = $_GET['date'] ?? date('Y-m-d');
$today = todays_attendance($currentUserId);
$roster = $isManager ? attendance_for_date($viewDate) : [];
$myHistory = attendance_history($currentUserId, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));

// Monthly attendance % — managers can check any employee, employees see their own.
$summaryMonth = $_GET['summary_month'] ?? date('Y-m');
$summaryUserId = $isManager
    ? (isset($_GET['summary_user_id']) && $_GET['summary_user_id'] !== '' ? (int) $_GET['summary_user_id'] : null)
    : $currentUserId;
$monthlySummary = $summaryUserId !== null ? monthly_attendance_summary($summaryUserId, $summaryMonth) : null;
$allEmployees = $isManager ? db()->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll() : [];

$pageTitle = 'Attendance';
$activeMenu = 'attendance';
$breadcrumbs = [['label' => 'Attendance', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<h1 class="h4 mb-3">Attendance</h1>

<div class="card mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">My Attendance Today</h2></div>
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <?php if ($today === false): ?>
            <form method="POST" action="<?= e(url('hr/attendance.php')) ?>" class="js-punch-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="punch_in">
                <input type="hidden" name="latitude" class="js-punch-lat" value="">
                <input type="hidden" name="longitude" class="js-punch-lng" value="">
                <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-box-arrow-in-right"></i> Punch In</button>
            </form>
            <span class="text-muted small">You haven't punched in today.</span>
        <?php else: ?>
            <span class="badge <?= e(attendance_status_badge_class($today['status'])) ?>">Checked in <?= e($today['check_in_time'] ?? '') ?></span>
            <?php if ($today['check_out_time']): ?>
                <span class="text-muted small">Checked out <?= e($today['check_out_time']) ?></span>
            <?php else: ?>
                <form method="POST" action="<?= e(url('hr/attendance.php')) ?>" class="js-punch-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="punch_out">
                    <input type="hidden" name="latitude" class="js-punch-lat" value="">
                    <input type="hidden" name="longitude" class="js-punch-lng" value="">
                    <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-right"></i> Punch Out</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <span class="text-muted small d-none" id="punchLocationHint"><i class="bi bi-geo-alt"></i> Getting your location…</span>
    </div>
    <div class="card-body pt-0 d-none" id="punchLocationError">
        <div class="alert alert-warning py-2 px-3 mb-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span class="small"><i class="bi bi-geo-alt-fill"></i> Location access is required to mark attendance. Please allow location access and try again.</span>
            <button type="button" class="btn btn-sm btn-warning" id="punchLocationRetry">Retry</button>
        </div>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
// Location is mandatory before attendance can be marked. Clicking
// Punch In/Out no longer submits the form directly: it first requests
// the device's actual coordinates (browser Geolocation API — never a
// fixed/office location) and only submits, with those coordinates
// attached, once a valid position has been obtained. If permission is
// denied, unsupported, or the location can't be obtained, the request
// is NOT sent — the user sees a message asking them to allow location
// access and a Retry button (see hr/attendance.php's punch_in/punch_out
// handling above, which also rejects any request missing a location).
document.querySelectorAll('.js-punch-form').forEach(function (form) {
    var latField = form.querySelector('.js-punch-lat');
    var lngField = form.querySelector('.js-punch-lng');
    var hint = document.getElementById('punchLocationHint');
    var errorBox = document.getElementById('punchLocationError');
    var submitBtn = form.querySelector('button[type="submit"]');

    var requestLocation = function () {
        if (errorBox) errorBox.classList.add('d-none');

        if (!('geolocation' in navigator)) {
            if (errorBox) errorBox.classList.remove('d-none');
            return;
        }

        if (hint) hint.classList.remove('d-none');
        if (submitBtn) submitBtn.disabled = true;

        navigator.geolocation.getCurrentPosition(
            function (position) {
                // Valid latitude/longitude obtained — continue automatically
                // with the existing attendance submit flow.
                latField.value = position.coords.latitude;
                lngField.value = position.coords.longitude;
                if (hint) hint.classList.add('d-none');
                if (submitBtn) submitBtn.disabled = false;
                form.dataset.locationResolved = '1';
                form.submit();
            },
            function () {
                // Denied, unavailable, or timed out — do NOT mark
                // attendance. Keep it pending and offer a retry.
                if (hint) hint.classList.add('d-none');
                if (submitBtn) submitBtn.disabled = false;
                if (errorBox) errorBox.classList.remove('d-none');
            },
            { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
        );
    };

    form.addEventListener('submit', function (e) {
        if (form.dataset.locationResolved === '1') {
            return; // valid location already obtained for this submit — let it through
        }
        e.preventDefault();
        requestLocation();
    });

    var retryBtn = document.getElementById('punchLocationRetry');
    if (retryBtn) {
        retryBtn.addEventListener('click', requestLocation);
    }
});
</script>

<div class="card mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Monthly Attendance %</h2></div>
    <div class="card-body">
        <form method="GET" action="<?= e(url('hr/attendance.php')) ?>" class="row g-2 align-items-end mb-3">
            <?php if ($isManager): ?>
                <div class="col-12 col-md-5">
                    <label class="form-label small">Employee</label>
                    <select name="summary_user_id" class="form-select form-select-sm js-auto-submit">
                        <option value="">Select employee…</option>
                        <?php foreach ($allEmployees as $emp): ?>
                            <option value="<?= e((string) $emp['id']) ?>" <?= $summaryUserId === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-8 col-md-4">
                <label class="form-label small">Month</label>
                <input type="month" name="summary_month" class="form-control form-control-sm js-auto-submit" value="<?= e($summaryMonth) ?>">
            </div>
            <?php if ($isManager): ?>
                <div class="col-4 col-md-3">
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">Check</button>
                </div>
            <?php endif; ?>
        </form>

        <?php if ($monthlySummary !== null): ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 text-center attendance-summary-table">
                    <thead class="table-light">
                        <tr><th>Present</th><th>Absent</th><th>Half Day</th><th>On Leave</th><th>Days in Month</th><th>Attendance %</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= e((string) $monthlySummary['present']) ?></td>
                            <td><?= e((string) $monthlySummary['absent']) ?></td>
                            <td><?= e((string) $monthlySummary['half_day']) ?></td>
                            <td><?= e((string) $monthlySummary['on_leave']) ?></td>
                            <td><?= e((string) $monthlySummary['total_days_in_month']) ?></td>
                            <td class="fw-semibold"><?= e(number_format($monthlySummary['percentage'], 2)) ?>%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted small mb-0">Select an employee to see their attendance percentage for the month.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($isManager): ?>
<div class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Team Roster</h2>
        <form method="GET" action="<?= e(url('hr/attendance.php')) ?>" class="d-flex gap-2">
            <input type="date" name="date" class="form-control form-control-sm js-auto-submit" value="<?= e($viewDate) ?>">
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr><th>Employee</th><th>Status</th><th>Check In</th><th>Check Out</th><th>Location</th><th class="text-end">Update</th></tr></thead>
                <tbody>
                    <?php foreach ($roster as $row): ?>
                        <tr>
                            <td><?= e($row['full_name']) ?></td>
                            <td><span class="badge <?= e(attendance_status_badge_class($row['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $row['status'] ?? 'not marked'))) ?></span></td>
                            <td><?= field_or($row['check_in_time'] ?? null, 'Not checked in') ?></td>
                            <td><?= field_or($row['check_out_time'] ?? null, 'Not checked out') ?></td>
                            <td>
                                <?php
                                    // Whichever punch actually has coordinates — the check-in point
                                    // if present, otherwise the check-out point. Always the real
                                    // device coordinates captured by the Geolocation API at that
                                    // punch (see punch_in()/punch_out() in includes/hr.php) — never
                                    // a generic/fixed location.
                                    $lat = $row['check_in_latitude'] ?? $row['check_out_latitude'] ?? null;
                                    $lng = $row['check_in_longitude'] ?? $row['check_out_longitude'] ?? null;
                                ?>
                                <?php if ($lat !== null && $lng !== null): ?>
                                    <div class="small text-muted mb-1">Lat: <?= e(number_format((float) $lat, 6)) ?><br>Lng: <?= e(number_format((float) $lng, 6)) ?></div>
                                    <a href="<?= e(attendance_location_maps_url((float) $lat, (float) $lng)) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-geo-alt"></i> View Location</a>
                                <?php else: ?>
                                    <span class="text-muted small">Not Captured</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <form method="POST" action="<?= e(url('hr/attendance.php')) ?>" class="d-flex gap-1 justify-content-end">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_attendance">
                                    <input type="hidden" name="user_id" value="<?= e((string) $row['user_id']) ?>">
                                    <input type="hidden" name="attendance_date" value="<?= e($viewDate) ?>">
                                    <select name="status" class="form-select form-select-sm" style="width:120px;">
                                        <?php foreach (['present','absent','half_day','on_leave'] as $s): ?>
                                            <option value="<?= e($s) ?>" <?= $row['status'] === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0">My Last 30 Days</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Date</th><th>Status</th><th>Check In</th><th>Check Out</th></tr></thead>
                <tbody>
                    <?php if (empty($myHistory)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No attendance records yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($myHistory as $row): ?>
                        <tr>
                            <td><?= e($row['attendance_date']) ?></td>
                            <td><span class="badge <?= e(attendance_status_badge_class($row['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $row['status']))) ?></span></td>
                            <td><?= field_or($row['check_in_time'] ?? null, 'Not checked in') ?></td>
                            <td><?= field_or($row['check_out_time'] ?? null, 'Not checked out') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
document.querySelectorAll('.js-auto-submit').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>