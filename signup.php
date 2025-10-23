<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = "localhost";
$user = "root";
$pass = "";
$db   = "mars";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // === Retrieve POST data ===
    $role       = $_POST['role'];

    $username      = trim($_POST['username']);
    $password_raw  = $_POST['password'];
    $password      = password_hash($password_raw, PASSWORD_DEFAULT);
    $terms         = isset($_POST['terms']) ? 1 : 0;

    $required_fields = [
       
        'role'         => $role,    
        'username'     => $username,
        'password'     => $password_raw,
    ];

    foreach ($required_fields as $field => $value) {
        if (empty($value)) {
            echo "Please fill in the $field field";
            exit;
        }
    }

       // Check if username already exists
    $checkUser = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $checkUser->bind_param("s", $username);
    $checkUser->execute();
    $checkUser->store_result();

    if ($checkUser->num_rows > 0) {
        echo "Error: Username already taken!";
        $checkUser->close();
        $conn->close();
        exit();
    }
    $checkUser->close();

                // === Prevent normal users from creating an Admin account ===
            if (strtolower($role) === "admin") {
                echo "Error: You cannot register as Admin.";
                exit;
            }

    // === Insert user into database ===
    $stmt = $conn->prepare("INSERT INTO users 
        ( role, username, password, terms_accepted)
        VALUES (?, ?, ?, ?)");
    
    $stmt->bind_param(
        "sssi", 
         $role, $username, $password, $terms
    );

    if ($stmt->execute()) {
        echo "Success";
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
    exit();
}
?>

