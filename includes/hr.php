<?php

declare(strict_types=1);

/**
 * includes/hr.php
 * Employee HR business logic: attendance punch, leave requests +
 * approval, salary records, and meetings + attendees.
 */

// ---------------------------------------------------------------------
// Attendance
// ---------------------------------------------------------------------

/**
 * Location is mandatory for attendance: a punch is only ever recorded with
 * real coordinates captured by the browser's Geolocation API at that moment
 * (see the punch-in/out form on hr/attendance.php). This guards the backend
 * itself so a punch can never be created without valid lat/lng, even if the
 * frontend check is bypassed (e.g. a direct POST to this endpoint).
 */
function attendance_location_is_valid(?float $latitude, ?float $longitude): bool
{
    if ($latitude === null || $longitude === null) {
        return false;
    }
    return $latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180;
}

function punch_in(int $userId, ?float $latitude = null, ?float $longitude = null): bool
{
    if (!attendance_location_is_valid($latitude, $longitude)) {
        return false;
    }

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare(
        'INSERT INTO employee_attendance
        (user_id, attendance_date, check_in_time, check_in_latitude, check_in_longitude, status, created_at, updated_at)
        VALUES (:uid, :date, :time, :lat, :lng, "present", :created, :updated)'
    );

    return $stmt->execute([
        'uid'     => $userId,
        'date'    => $today,
        'time'    => date('H:i:s'),
        // Only ever the device's own coordinates from the browser
        // Geolocation API (see the punch-in form on hr/attendance.php)
        // — never a fixed/fallback location. Location is now required
        // (see attendance_location_is_valid() above), so these are
        // always a real captured position by the time we get here.
        'lat'     => $latitude,
        'lng'     => $longitude,
        'created' => $now,
        'updated' => $now,
    ]);
}

function punch_out(int $userId, ?float $latitude = null, ?float $longitude = null): bool
{
    if (!attendance_location_is_valid($latitude, $longitude)) {
        return false;
    }

    $stmt = db()->prepare(
        'UPDATE employee_attendance SET check_out_time = :time, check_out_latitude = :lat, check_out_longitude = :lng, updated_at = :now
         WHERE user_id = :uid AND attendance_date = :date'
    );
    return $stmt->execute([
        'time' => date('H:i:s'),
        'lat' => $latitude,
        'lng' => $longitude,
        'now' => date('Y-m-d H:i:s'),
        'uid' => $userId,
        'date' => date('Y-m-d'),
    ]);
}

function todays_attendance(int $userId): array|false
{
    $stmt = db()->prepare('SELECT * FROM employee_attendance WHERE user_id = :uid AND attendance_date = :date');
    $stmt->execute(['uid' => $userId, 'date' => date('Y-m-d')]);
    return $stmt->fetch();
}

function mark_attendance(int $userId, string $date, string $status, ?string $notes = null): bool
{
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare('INSERT INTO employee_attendance(user_id, attendance_date, status, notes, created_at, updated_at)
    VALUES (:uid, :date, :status, :notes, :created, :updated)ON DUPLICATE KEY UPDATE status = VALUES(status),
    notes = VALUES(notes),updated_at = VALUES(updated_at)');
    return $stmt->execute([
        'uid'     => $userId,
        'date'    => $date,
        'status'  => $status,
        'notes'   => $notes,
        'created' => $now,
        'updated' => $now,
    ]);
}

/** @return array<int, array{user_id:int,full_name:string,attendance_date:?string,status:?string,check_in_time:?string,check_out_time:?string}> */
function attendance_for_date(string $date): array
{
    $stmt = db()->prepare(
        "SELECT u.id AS user_id, u.full_name, a.attendance_date, a.status, a.check_in_time, a.check_out_time,
                a.check_in_latitude, a.check_in_longitude, a.check_out_latitude, a.check_out_longitude
         FROM users u
         LEFT JOIN employee_attendance a ON a.user_id = u.id AND a.attendance_date = :date
         WHERE u.status = 'active' AND u.deleted_at IS NULL
         ORDER BY u.full_name ASC"
    );
    $stmt->execute(['date' => $date]);
    return $stmt->fetchAll();
}

function attendance_history(int $userId, string $startDate, string $endDate): array
{
    $stmt = db()->prepare(
        'SELECT * FROM employee_attendance WHERE user_id = :uid AND attendance_date BETWEEN :start AND :end ORDER BY attendance_date DESC'
    );
    $stmt->execute(['uid' => $userId, 'start' => $startDate, 'end' => $endDate]);
    return $stmt->fetchAll();
}

/** Google Maps link for a punch's captured coordinates — used by the "View Location" action on hr/attendance.php's Team Roster. */
function attendance_location_maps_url(float $latitude, float $longitude): string
{
    return 'https://www.google.com/maps?q=' . urlencode((string) $latitude) . ',' . urlencode((string) $longitude);
}

function attendance_status_badge_class(?string $status): string
{
    return match ($status) {
        'present' => 'text-bg-success',
        'half_day' => 'text-bg-warning',
        'on_leave' => 'text-bg-info',
        'absent' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
}

/**
 * Monthly attendance summary for one employee, used to work out how
 * much of that month's salary they've earned.
 *
 * Basis: calendar days in the month (India-style "30 day month" payroll,
 * not just working days). Any day in the month with no attendance row
 * at all counts as unpaid/absent — it simply isn't in $marked.
 *
 * On_leave is treated as a paid day (counts like present), per company
 * policy for approved leave. Half day counts as 0.5 of a day.
 *
 * @return array{present:int,absent:int,half_day:int,on_leave:int,
 *               marked_days:int,total_days_in_month:int,
 *               paid_equivalent_days:float,percentage:float}
 */
function monthly_attendance_summary(int $userId, string $payMonth): array
{
    $counts = ['present' => 0, 'absent' => 0, 'half_day' => 0, 'on_leave' => 0];

    $stmt = db()->prepare(
        'SELECT status, COUNT(*) AS cnt FROM employee_attendance
         WHERE user_id = :uid AND DATE_FORMAT(attendance_date, "%Y-%m") = :month
         GROUP BY status'
    );
    $stmt->execute(['uid' => $userId, 'month' => $payMonth]);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int) $row['cnt'];
        }
    }

    $markedDays = array_sum($counts);
    $totalDaysInMonth = (int) date('t', strtotime($payMonth . '-01'));

    // present + on_leave count as a full paid day, half_day counts as 0.5, absent counts as 0.
    $paidEquivalentDays = $counts['present'] + $counts['on_leave'] + ($counts['half_day'] * 0.5);

    $percentage = $totalDaysInMonth > 0 ? ($paidEquivalentDays / $totalDaysInMonth) * 100 : 0.0;

    return [
        'present' => $counts['present'],
        'absent' => $counts['absent'],
        'half_day' => $counts['half_day'],
        'on_leave' => $counts['on_leave'],
        'marked_days' => $markedDays,
        'total_days_in_month' => $totalDaysInMonth,
        'paid_equivalent_days' => $paidEquivalentDays,
        'percentage' => round($percentage, 2),
    ];
}

/**
 * Suggested salary for an employee for a given pay month, based on
 * their fixed monthly_salary and that month's attendance percentage.
 * Purely a suggestion — the Salary form still lets an admin edit the
 * amount before saving.
 *
 * @return array{summary:array,monthly_salary:float,suggested_amount:float}
 */
function suggested_salary_for_month(int $userId, string $payMonth): array
{
    $user = find_user_by_id($userId);
    $monthlySalary = $user !== false ? (float) ($user['monthly_salary'] ?? 0) : 0.0;

    $summary = monthly_attendance_summary($userId, $payMonth);
    $suggestedAmount = round($monthlySalary * ($summary['percentage'] / 100), 2);

    return [
        'summary' => $summary,
        'monthly_salary' => $monthlySalary,
        'suggested_amount' => $suggestedAmount,
    ];
}

// ---------------------------------------------------------------------
// Leave requests
// ---------------------------------------------------------------------

function apply_for_leave(int $userId, array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, reason, created_at)
         VALUES (:uid, :type, :start, :end, :reason, :now)'
    );
    $stmt->execute([
        'uid' => $userId,
        'type' => $data['leave_type'],
        'start' => $data['start_date'],
        'end' => $data['end_date'],
        'reason' => $data['reason'] ?? null,
        'now' => date('Y-m-d H:i:s'),
    ]);
    $leaveId = (int) db()->lastInsertId();

    notify_admins('leave', "Leave request from employee", $data['leave_type'] . ' — ' . $data['start_date'] . ' to ' . $data['end_date'], 'hr/leaves.php?id=' . $leaveId);

    return $leaveId;
}

function decide_leave_request(int $leaveId, string $decision, int $approverId): bool
{
    $stmt = db()->prepare(
        'UPDATE leave_requests SET status = :status, approved_by = :approver, approved_at = :now WHERE id = :id AND status = "pending"'
    );
    $ok = $stmt->execute(['status' => $decision, 'approver' => $approverId, 'now' => date('Y-m-d H:i:s'), 'id' => $leaveId]);

    $leaveStmt = db()->prepare('SELECT user_id, leave_type, start_date, end_date FROM leave_requests WHERE id = :id');
    $leaveStmt->execute(['id' => $leaveId]);
    $leave = $leaveStmt->fetch();
    if ($leave !== false) {
        create_notification(
            (int) $leave['user_id'],
            'leave',
            'Leave request ' . $decision,
            "{$leave['leave_type']} leave ({$leave['start_date']} to {$leave['end_date']}) was {$decision}.",
            'hr/leaves.php'
        );
    }

    return $ok;
}

function get_leave_requests(?int $userId = null, ?string $status = null): array
{
    $where = ['1=1'];
    $params = [];
    if ($userId !== null) {
        $where[] = 'lr.user_id = :uid';
        $params['uid'] = $userId;
    }
    if ($status !== null) {
        $where[] = 'lr.status = :status';
        $params['status'] = $status;
    }
    $stmt = db()->prepare(
        'SELECT lr.*, u.full_name, approver.full_name AS approver_name
         FROM leave_requests lr
         JOIN users u ON u.id = lr.user_id
         LEFT JOIN users approver ON approver.id = lr.approved_by
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY lr.created_at DESC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function leave_status_badge_class(string $status): string
{
    return match ($status) {
        'pending' => 'text-bg-warning',
        'approved' => 'text-bg-success',
        'rejected' => 'text-bg-danger',
        'cancelled' => 'text-bg-secondary',
        default => 'text-bg-secondary',
    };
}

// ---------------------------------------------------------------------
// Salary
// ---------------------------------------------------------------------

/**
 * Creates a new salary record, or — when $data['id'] is set (the Edit
 * flow on hr/salaries.php, see find_salary_record() below) — updates
 * the existing one in place instead of inserting a duplicate. Editing
 * this way means the corrected figures are what every future
 * "Download" (hr/payslip.php) renders — the payslip is always
 * generated fresh from this row, never a separately stored file, so
 * there's no separate "regenerate" step.
 */
function save_salary_record(array $data, int $createdBy): int
{
    $netPay = (float) $data['basic_pay'] + (float) $data['allowances'] - (float) $data['deductions'];
    $recordId = (int) ($data['id'] ?? 0);

    if ($recordId > 0) {
        $stmt = db()->prepare(
            'UPDATE employee_salaries
             SET user_id = :uid, pay_month = :month, basic_pay = :basic, allowances = :allow,
                 deductions = :deduct, net_pay = :net, status = :status, paid_on = :paid_on,
                 notes = :notes, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'uid' => $data['user_id'],
            'month' => $data['pay_month'],
            'basic' => $data['basic_pay'],
            'allow' => $data['allowances'],
            'deduct' => $data['deductions'],
            'net' => $netPay,
            'status' => $data['status'] ?? 'pending',
            'paid_on' => !empty($data['paid_on']) ? $data['paid_on'] : null,
            'notes' => $data['notes'],
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $recordId,
        ]);

        return $recordId;
    }

    $stmt = db()->prepare(
        'INSERT INTO employee_salaries
        (user_id, pay_month, basic_pay, allowances, deductions, net_pay, status, paid_on, notes, created_by, created_at, updated_at)
        VALUES
        (:uid, :month, :basic, :allow, :deduct, :net, :status, :paid_on, :notes, :created_by, :created_at, :updated_at)'
    );

    $stmt->execute([
        'uid' => $data['user_id'],
        'month' => $data['pay_month'],
        'basic' => $data['basic_pay'],
        'allow' => $data['allowances'],
        'deduct' => $data['deductions'],
        'net' => $netPay,
        'status' => $data['status'] ?? 'pending',
        'paid_on' => !empty($data['paid_on']) ? $data['paid_on'] : null,
        'notes' => $data['notes'],
        'created_by' => $createdBy,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) db()->lastInsertId();
}

/** Single salary record by id, with the employee's name/designation/department — used by the Edit form and hr/payslip.php. */
function find_salary_record(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT es.*, u.full_name, u.designation, u.department
         FROM employee_salaries es
         JOIN users u ON u.id = es.user_id
         WHERE es.id = :id'
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

function get_salary_records(?string $payMonth = null, ?int $userId = null): array
{
    $where = ['1=1'];
    $params = [];
    if ($payMonth !== null) {
        $where[] = 'es.pay_month = :month';
        $params['month'] = $payMonth;
    }
    if ($userId !== null) {
        $where[] = 'es.user_id = :uid';
        $params['uid'] = $userId;
    }
    $stmt = db()->prepare(
        'SELECT es.*, u.full_name
         FROM employee_salaries es
         JOIN users u ON u.id = es.user_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY es.pay_month DESC, u.full_name ASC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Meetings
// ---------------------------------------------------------------------

/**
 * Creates a meeting. Optionally tied to a lead or client (see
 * database/migration_035_meeting_lead_client_link.sql) — pass
 * $data['lead_id'] or $data['client_id'] to attach it, so it shows up
 * in that lead's/client's own meeting history (get_meetings_for_lead(),
 * get_meetings_for_client()) rather than only the general HR list.
 * A lead/client can have any number of these; nothing here overwrites
 * a previous meeting — each call inserts a new, separate row.
 */
function create_meeting(array $data, array $attendeeUserIds, int $createdBy): int
{
    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare(
        'INSERT INTO meetings
        (uuid, title, description, meeting_date, start_time, end_time, location, lead_id, client_id, created_by, created_at, updated_at)
        VALUES (:uuid, :title, :description, :date, :start, :end, :location, :lead_id, :client_id, :created_by, :created, :updated)'
    );

    $stmt->execute([
        'uuid'        => generate_uuid_v4(),
        'title'       => $data['title'],
        'description' => $data['description'] ?? null,
        'date'        => $data['meeting_date'],
        'start'       => $data['start_time'] ?: null,
        'end'         => $data['end_time'] ?: null,
        'location'    => $data['location'] ?? null,
        'lead_id'     => $data['lead_id'] ?? null,
        'client_id'   => $data['client_id'] ?? null,
        'created_by'  => $createdBy,
        'created'     => $now,
        'updated'     => $now,
    ]);

    $meetingId = (int) db()->lastInsertId();

    $attendeeStmt = db()->prepare(
        'INSERT IGNORE INTO meeting_attendees (meeting_id, user_id)
         VALUES (:mid, :uid)'
    );

    // A lead/client meeting may have no attendees picked at all (e.g.
    // quickly scheduled while creating a lead) — attendee notifications
    // simply don't fire in that case, same as any other meeting.
    foreach ($attendeeUserIds as $userId) {
        $attendeeStmt->execute([
            'mid' => $meetingId,
            'uid' => $userId
        ]);

        create_notification(
            (int)$userId,
            'meeting',
            'Meeting scheduled: ' . $data['title'],
            "On {$data['meeting_date']}" . ($data['start_time'] ? " at {$data['start_time']}" : ''),
            'hr/meetings.php?id=' . $meetingId
        );
    }

    return $meetingId;
}

function get_meetings(?int $userId = null): array
{
    if ($userId !== null) {
        // A user's own meetings list includes meetings they're invited to
        // AND meetings they scheduled themselves (even if they didn't
        // add themselves as an attendee).
        $stmt = db()->prepare(
            'SELECT DISTINCT m.* FROM meetings m
             LEFT JOIN meeting_attendees ma ON ma.meeting_id = m.id AND ma.user_id = :uid1
             WHERE ma.user_id = :uid2 OR m.created_by = :uid3
             ORDER BY m.meeting_date DESC, m.start_time DESC'
        );
        $stmt->execute(['uid1' => $userId, 'uid2' => $userId, 'uid3' => $userId]);
    } else {
        $stmt = db()->query('SELECT * FROM meetings ORDER BY meeting_date DESC, start_time DESC');
    }
    return $stmt->fetchAll();
}

/**
 * Full meeting history for one lead — every meeting ever scheduled
 * against it (past/held, missed, and upcoming), newest first. Nothing
 * is ever overwritten here: "Schedule Next Meeting" just calls
 * create_meeting() again, so this list only ever grows.
 */
function get_meetings_for_lead(int $leadId): array
{
    $stmt = db()->prepare('SELECT * FROM meetings WHERE lead_id = :id ORDER BY meeting_date DESC, start_time DESC');
    $stmt->execute(['id' => $leadId]);
    return $stmt->fetchAll();
}

/** Full meeting history for one client — see get_meetings_for_lead(). */
function get_meetings_for_client(int $clientId): array
{
    $stmt = db()->prepare('SELECT * FROM meetings WHERE client_id = :id ORDER BY meeting_date DESC, start_time DESC');
    $stmt->execute(['id' => $clientId]);
    return $stmt->fetchAll();
}

/**
 * Marks a meeting held with an attendance note. Shared by hr/meetings.php
 * and the lead/client "Schedule Next Meeting" widgets (includes/meeting_widget.php)
 * so all three use the exact same update, not copies of it.
 */
function mark_meeting_held(int $meetingId, string $notes): bool
{
    $stmt = db()->prepare(
        "UPDATE meetings SET status = 'held', attendance_notes = :notes, updated_at = :now
         WHERE id = :id AND status != 'held'"
    );
    return $stmt->execute(['notes' => $notes, 'now' => date('Y-m-d H:i:s'), 'id' => $meetingId]);
}

function get_meeting_attendees(int $meetingId): array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.full_name FROM meeting_attendees ma JOIN users u ON u.id = ma.user_id WHERE ma.meeting_id = :id ORDER BY u.full_name'
    );
    $stmt->execute(['id' => $meetingId]);
    return $stmt->fetchAll();
}

/**
 * Builds calendar events (one per meeting in the given month, optionally
 * scoped to one user's own meetings) for the shared calendar widget on
 * hr/meetings.php.
 */
function get_meeting_calendar_events(string $monthStart, string $monthEnd, ?int $userId = null): array
{
    if ($userId !== null) {
        $stmt = db()->prepare(
            'SELECT DISTINCT m.* FROM meetings m
             LEFT JOIN meeting_attendees ma ON ma.meeting_id = m.id AND ma.user_id = :uid1
             WHERE (ma.user_id = :uid2 OR m.created_by = :uid3)
               AND m.meeting_date BETWEEN :start AND :end
             ORDER BY m.meeting_date'
        );
        $stmt->execute(['uid1' => $userId, 'uid2' => $userId, 'uid3' => $userId, 'start' => $monthStart, 'end' => $monthEnd]);
    } else {
        $stmt = db()->prepare('SELECT * FROM meetings WHERE meeting_date BETWEEN :start AND :end ORDER BY meeting_date');
        $stmt->execute(['start' => $monthStart, 'end' => $monthEnd]);
    }

    $events = [];
    foreach ($stmt->fetchAll() as $row) {
        $status = match ($row['status']) {
            'held' => 'done',
            'missed' => 'missed',
            'cancelled' => 'cancelled',
            default => 'upcoming',
        };

        $events[] = [
            'date' => $row['meeting_date'],
            'initials' => mb_substr($row['title'], 0, 2),
            'title' => $row['title'],
            'subtitle' => ($row['start_time'] ? $row['start_time'] . ' · ' : '') . ucfirst($row['status']) . ($row['location'] ? ' · ' . $row['location'] : ''),
            'status' => $status,
            'url' => url('hr/meetings.php'),
        ];
    }

    return $events;
}

// ---------------------------------------------------------------------
// Missed meeting automation
//
// Same on-demand pattern as process_missed_leads() (see includes/leads.php)
// — no cron in this environment, so this runs opportunistically on every
// meetings list view and can also be triggered manually. A meeting whose
// date/end-time has passed while still "scheduled" (nobody marked it
// held or cancelled) becomes "missed", and every attendee plus admins
// are notified.
// ---------------------------------------------------------------------

function process_missed_meetings(): array
{
    $now = date('Y-m-d H:i:s');

    // A meeting counts as overdue once its end time has passed (or, if
    // no end time was set, once its start time has — or just its date
    // if neither time was given, meaning the whole day has elapsed).
    $stmt = db()->prepare(
        "SELECT id, title, meeting_date, start_time, end_time FROM meetings
         WHERE status = 'scheduled'
           AND TIMESTAMP(meeting_date, COALESCE(end_time, start_time, '23:59:59')) < :now"
    );
    $stmt->execute(['now' => $now]);
    $overdue = $stmt->fetchAll();

    foreach ($overdue as $meeting) {
        $meetingId = (int) $meeting['id'];

        $stmt2 = db()->prepare("UPDATE meetings SET status = 'missed', updated_at = :now WHERE id = :id");
        $stmt2->execute(['now' => $now, 'id' => $meetingId]);

        $attendees = get_meeting_attendees($meetingId);
        notify_missed_meeting($meetingId, $meeting['title'], $attendees);
    }

    return ['missed' => count($overdue), 'checked' => count($overdue)];
}

// ---------------------------------------------------------------------
// Daily Work Updates
// ---------------------------------------------------------------------
// One entry per employee per calendar day: a heading + description of
// what they worked on, with an optional reference link and optional
// supporting documents (daily_work_update_documents, same pattern as
// project_completion_documents). Submitting again on the same day
// edits that day's entry rather than creating a second one, thanks to
// the uq_daily_work_updates_user_date unique key.

/**
 * Creates today's entry for a user, or updates it if one already
 * exists for today (upsert on the (user_id, update_date) unique key).
 * Returns the update's id either way.
 */
function submit_daily_update(int $userId, array $data): int
{
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare(
        'INSERT INTO daily_work_updates (user_id, update_date, heading, description, reference_link, created_at, updated_at)
         VALUES (:uid, :date, :heading, :description, :link, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE heading = VALUES(heading), description = VALUES(description),
             reference_link = VALUES(reference_link), updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        'uid' => $userId,
        'date' => $today,
        'heading' => $data['heading'],
        'description' => $data['description'],
        'link' => $data['reference_link'] ?? null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $idStmt = db()->prepare('SELECT id FROM daily_work_updates WHERE user_id = :uid AND update_date = :date');
    $idStmt->execute(['uid' => $userId, 'date' => $today]);
    return (int) $idStmt->fetch()['id'];
}

/** Today's entry for a user, or null if they haven't submitted yet. */
function get_todays_update(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM daily_work_updates WHERE user_id = :uid AND update_date = :date');
    $stmt->execute(['uid' => $userId, 'date' => date('Y-m-d')]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * @param array{user_id?:int,date?:string,search?:string} $filters
 */
function get_daily_updates(array $filters = [], int $limit = 200): array
{
    $where = [];
    $params = [];

    if (!empty($filters['user_id'])) {
        $where[] = 'du.user_id = :user_id';
        $params['user_id'] = $filters['user_id'];
    }
    if (!empty($filters['date'])) {
        $where[] = 'du.update_date = :date';
        $params['date'] = $filters['date'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(du.heading LIKE :search1 OR du.description LIKE :search2)';
        $term = '%' . $filters['search'] . '%';
        $params['search1'] = $term;
        $params['search2'] = $term;
    }

    $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

    $stmt = db()->prepare(
        "SELECT du.*, u.full_name
         FROM daily_work_updates du
         JOIN users u ON u.id = du.user_id
         {$whereSql}
         ORDER BY du.update_date DESC, u.full_name ASC
         LIMIT " . (int) $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * For managers: today's submission status across every active
 * employee — who has logged an update today and who hasn't yet.
 *
 * @return array<int, array{id:int,full_name:string,submitted:bool,heading:?string}>
 */
function get_todays_update_status(): array
{
    $stmt = db()->query(
        "SELECT u.id, u.full_name, du.heading, du.id AS update_id
         FROM users u
         LEFT JOIN daily_work_updates du ON du.user_id = u.id AND du.update_date = CURDATE()
         WHERE u.status = 'active' AND u.deleted_at IS NULL
         ORDER BY (du.id IS NULL) DESC, u.full_name ASC"
    );
    $rows = $stmt->fetchAll();

    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'full_name' => $row['full_name'],
        'submitted' => $row['update_id'] !== null,
        'heading' => $row['heading'],
    ], $rows);
}

function add_daily_update_document(int $updateId, ?int $uploadedBy, array $uploadResult): int
{
    $stmt = db()->prepare(
        'INSERT INTO daily_work_update_documents (update_id, uploaded_by, original_filename, stored_filename, file_size_bytes, mime_type, created_at)
         VALUES (:update_id, :uploaded_by, :original, :stored, :size, :mime, :now)'
    );
    $stmt->execute([
        'update_id' => $updateId,
        'uploaded_by' => $uploadedBy,
        'original' => $uploadResult['original_filename'],
        'stored' => $uploadResult['stored_filename'],
        'size' => $uploadResult['size'],
        'mime' => $uploadResult['mime'],
        'now' => date('Y-m-d H:i:s'),
    ]);
    return (int) db()->lastInsertId();
}

function get_daily_update_documents(int $updateId): array
{
    $stmt = db()->prepare('SELECT * FROM daily_work_update_documents WHERE update_id = :update_id ORDER BY created_at DESC');
    $stmt->execute(['update_id' => $updateId]);
    return $stmt->fetchAll();
}

function find_daily_update_document(int $documentId): array|false
{
    $stmt = db()->prepare('SELECT * FROM daily_work_update_documents WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $documentId]);
    return $stmt->fetch();
}

/** Owner of the parent update (for download/delete access checks). */
function daily_update_owner(int $updateId): ?int
{
    $stmt = db()->prepare('SELECT user_id FROM daily_work_updates WHERE id = :id');
    $stmt->execute(['id' => $updateId]);
    $row = $stmt->fetch();
    return $row === false ? null : (int) $row['user_id'];
}

/** Fetch a single daily update by id, or false if it doesn't exist. */
function find_daily_update(int $updateId): array|false
{
    $stmt = db()->prepare('SELECT * FROM daily_work_updates WHERE id = :id');
    $stmt->execute(['id' => $updateId]);
    return $stmt->fetch();
}

/**
 * Lets an employee edit one of their own past entries (not just today's).
 * Returns true if a row was actually updated.
 */
function update_daily_update(int $updateId, int $userId, array $data): bool
{
    $stmt = db()->prepare(
        'UPDATE daily_work_updates
         SET heading = :heading, description = :description, reference_link = :link, updated_at = :now
         WHERE id = :id AND user_id = :uid'
    );
    $stmt->execute([
        'heading' => $data['heading'],
        'description' => $data['description'],
        'link' => $data['reference_link'] ?? null,
        'now' => date('Y-m-d H:i:s'),
        'id' => $updateId,
        'uid' => $userId,
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * Lets an employee delete one of their own entries. Attached documents are
 * removed automatically via ON DELETE CASCADE on daily_work_update_documents.
 */
function delete_own_daily_update(int $updateId, int $userId): bool
{
    $stmt = db()->prepare('DELETE FROM daily_work_updates WHERE id = :id AND user_id = :uid');
    $stmt->execute(['id' => $updateId, 'uid' => $userId]);
    return $stmt->rowCount() > 0;
}

/**
 * Admin/super_admin gives (or changes) a 1-5 star rating on an
 * employee's daily update.
 */
function rate_daily_update(int $updateId, int $rating, int $ratedBy): bool
{
    if ($rating < 1 || $rating > 5) {
        return false;
    }
    $stmt = db()->prepare(
        'UPDATE daily_work_updates SET rating = :rating, rated_by = :rated_by, rated_at = :now WHERE id = :id'
    );
    $stmt->execute([
        'rating' => $rating,
        'rated_by' => $ratedBy,
        'now' => date('Y-m-d H:i:s'),
        'id' => $updateId,
    ]);
    return $stmt->rowCount() > 0;
}

/**
 * Monthly rating roll-up for an employee's profile: how many of their
 * updates this month were rated, and the average — rounded to the
 * nearest whole star for the 5-star display.
 *
 * @return array{average: ?float, rounded: int, rated_count: int, total_count: int}
 */
function monthly_rating_summary(int $userId, string $yearMonth): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS total_count,
                COUNT(rating) AS rated_count,
                AVG(rating) AS average
         FROM daily_work_updates
         WHERE user_id = :uid AND DATE_FORMAT(update_date, '%Y-%m') = :ym"
    );
    $stmt->execute(['uid' => $userId, 'ym' => $yearMonth]);
    $row = $stmt->fetch();

    $average = $row['average'] !== null ? (float) $row['average'] : null;

    return [
        'average' => $average,
        'rounded' => $average !== null ? (int) round($average) : 0,
        'rated_count' => (int) $row['rated_count'],
        'total_count' => (int) $row['total_count'],
    ];
}

/**
 * Last N months of rating summaries for an employee (most recent first),
 * for the "Monthly Ratings" history strip on their profile.
 *
 * @return array<int, array{month:string, average:?float, rounded:int, rated_count:int, total_count:int}>
 */
function monthly_rating_history(int $userId, int $months = 6): array
{
    $result = [];
    for ($i = 0; $i < $months; $i++) {
        $ym = date('Y-m', strtotime("-{$i} months"));
        $summary = monthly_rating_summary($userId, $ym);
        $summary['month'] = $ym;
        $result[] = $summary;
    }
    return $result;
}