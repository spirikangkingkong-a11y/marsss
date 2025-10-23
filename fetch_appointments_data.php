<?php
header('Content-Type: application/json');

$host = "localhost";
$user = "root";
$pass = "";
$db   = "mars";
$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die(json_encode(["error" => $conn->connect_error]));
}

// Get selected year
$year = $_GET['year'] ?? 'current';

// Prepare labels & data
$labels = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
$data   = array_fill(0, 12, 0);

$labels_all = [];
$data_all   = [];

if ($year === 'all') {
    $sql = "
      SELECT YEAR(appointment_date) AS year, MONTH(appointment_date) AS month_num, COUNT(*) AS total
      FROM appointments
      GROUP BY YEAR(appointment_date), MONTH(appointment_date)
      ORDER BY YEAR(appointment_date), MONTH(appointment_date)
    ";
} else {
    $safeYear = ($year === 'current') ? date('Y') : (int)$year;
    $sql = "
      SELECT MONTH(appointment_date) AS month_num, COUNT(*) AS total
      FROM appointments
      WHERE YEAR(appointment_date) = $safeYear
      GROUP BY MONTH(appointment_date)
      ORDER BY MONTH(appointment_date)
    ";
}

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if ($year === 'all') {
            $label = $row['year'] . '-' . date('M', mktime(0, 0, 0, $row['month_num'], 1));
            $labels_all[] = $label;
            $data_all[] = (int)$row['total'];
        } else {
            $index = (int)$row['month_num'] - 1;
            $data[$index] = (int)$row['total'];
        }
    }
}

$conn->close();

echo json_encode($year === 'all' ? ["labels" => $labels_all, "data" => $data_all] : ["labels" => $labels, "data" => $data]);
