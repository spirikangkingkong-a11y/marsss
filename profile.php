<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$host = "localhost";
$user = "root";
$pass = "";
$db = "mars";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $firstname     = $_POST['firstname'];
    $middle        = $_POST['middle'];
    $surname       = $_POST['surname'];
    $birthdate     = $_POST['birthdate'];
    $gender        = $_POST['gender'];
    $contact       = $_POST['contact'];
    $email         = $_POST['email'];
    $province      = $_POST['province'];
    $city_address  = $_POST['city_address'];
    $brgy_address  = $_POST['brgy_address'];
    $home_address  = $_POST['home_address'];

    $update = $conn->prepare("UPDATE users SET firstname=?, middle=?, surname=?, birthdate=?, gender=?, contact=?, email=?, 
                              province=?, city_address=?, brgy_address=?, home_address=? WHERE id=?");

    $update->bind_param("sssssssssssi", $firstname, $middle, $surname, $birthdate, $gender, $contact, $email, $province, $city_address, $brgy_address, $home_address, $user_id);

    if ($update->execute()) {
        // Redirect based on role
        $role = $_SESSION['role']; // must be set during login
        if ($role == 'admin') {
            header("Location: admin.php?page=profile&updated=1");
        } elseif ($role == 'doctor') {
            header("Location: doctor.php?page=profile&updated=1");
        } elseif ($role == 'caregiver') {
            header("Location: caregiver.php?page=profile&updated=1");
        } elseif ($role == 'patient') {
            header("Location: patient.php?page=profile&updated=1");
        }
        exit();
    } else {
        echo "<script>alert('❌ Error updating profile!');</script>";
    }
}

// Fetch user info
$stmt = $conn->prepare("SELECT surname, firstname, middle, birthdate, gender, contact, email, province, home_address, brgy_address, city_address 
                        FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
?>
