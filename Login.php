<?php
session_start();

// Database credentials
$host = "localhost";
$user = "root";
$pass = "";
$db = "mars";


// Connect to MySQL
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Run only when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email_or_username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    if (empty($email_or_username) || empty($password) || empty($role)) {
        echo "<script>alert('Please fill all fields.'); window.location.href='login.php';</script>";
        exit;
    }

    // Find user
    $sql = "SELECT * FROM users WHERE (email = ? OR username = ?) LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $email_or_username, $email_or_username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Verify password
        if (password_verify($password, $user['password'])) {
            // Check role
            if (strcasecmp($role, $user['role']) === 0) {
                $_SESSION['user_id'] = $user['id'];    
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role']; 

                    // ✅ Reset activeTab to dashboard after successful login
                echo "<script>localStorage.setItem('activeTab', 'dashboard');</script>";

                // Redirect based on role
                switch (strtolower($user['role'])) {
                    case 'admin':
                        header("Location: admin.php");
                        break;
                    case 'doctor':
                        header("Location: doctor.php");
                        break;
                    case 'caregiver':
                        header("Location: caregiver.php");
                        break;
                    case 'patient':
                        header("Location: patient.php");
                        break;
                }
                exit;
            } else {
                echo "<script>alert('Selected role does not match your account.'); window.location.href='index.php';</script>";
            }
        } else {
            echo "<script>alert('Incorrect password.'); window.location.href='index.php';</script>";
        }
    } else {
        echo "<script>alert('User not found.'); window.location.href='index.php';</script>";
    }
    $stmt->close();
}

$conn->close();
