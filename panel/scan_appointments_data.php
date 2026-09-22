<?php
// panel/scan_appointments_data.php
// فید JSON نوبت‌های اسکن برای FullCalendar.  GET: from,to,doctor_id,type,status,q,case_id
require_once __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
$isDate = function (string $d): bool {
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
};
if (!$isDate($from)) $from = date('Y-m-01');
if (!$isDate($to))   $to   = date('Y-m-t');
// کمی حاشیه تا نوبت‌های مرزیِ هفته هم بیایند
$fromPadded = date('Y-m-d', strtotime($from . ' -7 days'));
$toPadded   = date('Y-m-d', strtotime($to . ' +7 days'));

$rows = getScanAppointments([
    'from'           => $fromPadded,
    'to'             => $toPadded,
    'id'             => !empty($_GET['id']) ? (int) $_GET['id'] : null,
    'doctor_id'      => !empty($_GET['doctor_id']) ? (int) $_GET['doctor_id'] : null,
    'case_id'        => !empty($_GET['case_id']) ? (int) $_GET['case_id'] : null,
    'type'           => !empty($_GET['type']) ? (string) $_GET['type'] : null,
    'status'         => !empty($_GET['status']) ? (string) $_GET['status'] : null,
    'q'              => trim((string) ($_GET['q'] ?? '')),
    'limit'          => 1000,
]);

$types    = scanAppointmentTypes();
$statuses = scanAppointmentStatuses();
$events   = [];
foreach ($rows as $r) {
    $typeKey   = (string) ($r['appt_type'] ?? 'scan');
    $statusKey = (string) ($r['status'] ?? 'scheduled');
    $typeMeta   = $types[$typeKey] ?? ['label' => $typeKey, 'icon' => '📌', 'color' => '#0ea5e9'];
    $statusMeta = $statuses[$statusKey] ?? ['label' => $statusKey, 'color' => '#64748b'];

    $serviceLabel = trim((string) ($r['service_short'] ?? ''));
    if ($serviceLabel === '') $serviceLabel = trim((string) ($r['service_title'] ?? ''));

    $events[] = [
        'id'              => (int) $r['id'],
        'title'           => trim(($r['doctor_name'] ?? '') . ' — ' . ($r['patient_name'] ?? $r['case_patient'] ?? '')),
        'start'           => $r['appt_date'] . 'T' . substr((string) $r['start_time'], 0, 5) . ':00',
        'end'             => !empty($r['end_time']) ? $r['appt_date'] . 'T' . substr((string) $r['end_time'], 0, 5) . ':00' : null,
        'allDay'          => false,
        'backgroundColor' => $typeMeta['color'],
        'borderColor'     => $typeMeta['color'],
        'classNames'      => $statusKey === 'done' ? ['sa-done'] : ($statusKey === 'canceled' ? ['sa-canceled'] : []),
        'extendedProps'   => [
            'appt_date'       => $r['appt_date'],
            'appt_date_jalali'=> toJalaliDateFormatted((string) $r['appt_date']),
            'start_time'      => substr((string) $r['start_time'], 0, 5),
            'end_time'        => !empty($r['end_time']) ? substr((string) $r['end_time'], 0, 5) : '',
            'doctor_id'       => (int) ($r['doctor_id'] ?? 0),
            'doctor_name'     => (string) ($r['doctor_name'] ?? ''),
            'case_id'         => (int) ($r['case_id'] ?? 0),
            'patient_name'    => (string) ($r['patient_name'] ?? $r['case_patient'] ?? ''),
            'case_patient'    => (string) ($r['case_patient'] ?? ''),
            'service'         => $serviceLabel,
            'appt_type'       => $typeKey,
            'appt_type_label' => $typeMeta['label'],
            'appt_type_icon'  => $typeMeta['icon'],
            'status'          => $statusKey,
            'status_label'    => $statusMeta['label'],
            'needs_scan_body' => (int) ($r['needs_scan_body'] ?? 0),
            'address'         => (string) ($r['address'] ?? ''),
            'phone'           => (string) ($r['phone'] ?? ''),
            'notes'           => (string) ($r['notes'] ?? ''),
            'branch_name'     => (string) ($r['branch_name'] ?? ''),
        ],
    ];
}

echo json_encode([
    'success' => true,
    'events'  => $events,
    'count'   => count($events),
    'range'   => ['from' => $fromPadded, 'to' => $toPadded],
], JSON_UNESCAPED_UNICODE);
