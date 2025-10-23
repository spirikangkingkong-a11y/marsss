
<?php
session_start();

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// === Database Connection ===
$host = "localhost";
$user = "root";
$pass = "";
$db = "mars";
$conn = new mysqli($host, $user, $pass, $db);

// Determine which section/tab should be active
$activeSection = $_GET['tab'] ?? 'dashboard';


if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ✅ Get logged-in user first
$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) {
    die("Not logged in. Please log in first.");
}

// === Patient Info ===
$stmt = $conn->prepare("SELECT id, surname, firstname, middle, birthdate, gender, contact, email, province, home_address, brgy_address, city_address 
                        FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    die("Error: Patient not found in database.");
}
// === Patient Appointments ===
$today = date("Y-m-d H:i:s");

// Upcoming appointments
$stmt = $conn->prepare("
    SELECT appointment_id, appointment_date, status,
           doctor_name, caregiver_name
    FROM appointments
    WHERE patient_id = ? AND appointment_date >= ?
    ORDER BY appointment_date ASC
");
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$upcoming_result = $stmt->get_result();
$upcoming_appointments = $upcoming_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Past appointments
$stmt = $conn->prepare("
    SELECT appointment_id, appointment_date, status,
           doctor_name, caregiver_name
    FROM appointments
    WHERE patient_id = ? AND appointment_date < ?
    ORDER BY appointment_date DESC
");
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$past_result = $stmt->get_result();
$past_appointments = $past_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();



// 1. DASHBOARD Total Upcoming Appointments
$stmt = $conn->prepare(" SELECT COUNT(*) FROM appointments WHERE patient_id=? AND appointment_date >= CURDATE() AND status != 'cancelled'");

$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($total_appointments);
$stmt->fetch();
$stmt->close();

// 2. DASHBOARD Current Caregiver (if assigned)
$stmt = $conn->prepare("SELECT caregiver_name FROM caregiver_requests 
                        WHERE patient_id=? AND status='approved' LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($current_caregiver);
$stmt->fetch();
$stmt->close();
if (!$current_caregiver) {
    $current_caregiver = "No caregiver assigned";
}


$stmt = $conn->prepare("SELECT id, surname, firstname, middle, birthdate, gender, contact, email, province, home_address, brgy_address, city_address 
                        FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

function getAppointments($conn, $user_id, $upcoming = true) {
    $today = date("Y-m-d H:i:s");
    $op = $upcoming ? ">=" : "<";

    if ($upcoming) {
        // Only upcoming and not cancelled
        $stmt = $conn->prepare("
            SELECT appointment_id, appointment_date, status, doctor_name, caregiver_name
            FROM appointments
            WHERE patient_id = ? AND appointment_date >= ? AND status != 'cancelled'
            ORDER BY appointment_date ASC
        ");
        $stmt->bind_param("is", $user_id, $today);
    } else {
        // Past appointments can include cancelled if you want
        $stmt = $conn->prepare("
            SELECT appointment_id, appointment_date, status, doctor_name, caregiver_name
            FROM appointments
            WHERE patient_id = ? AND appointment_date < ?
            ORDER BY appointment_date DESC
        ");
        $stmt->bind_param("is", $user_id, $today);
    }

    $stmt->execute();
    $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $res;
}


$upcoming_appointments = getAppointments($conn, $user_id, true);
$past_appointments = getAppointments($conn, $user_id, false);


// --- Handle Delete Notifications ---
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!empty($input['action']) && $input['action'] === "delete_notifications") {
        header("Content-Type: application/json");

        $ids = $input['ids'] ?? [];
        if (empty($ids)) {
            echo json_encode(["status" => "error", "message" => "No IDs provided."]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $stmt = $conn->prepare("DELETE FROM notifications WHERE id IN ($placeholders)");
        if ($stmt === false) {
            echo json_encode(["status" => "error", "message" => $conn->error]);
            exit;
        }

        $stmt->bind_param($types, ...$ids);
        $success = $stmt->execute();
        $stmt->close();

        echo json_encode($success ? ["status" => "success"] : ["status" => "error", "message" => $conn->error]);
        exit;
    }
}



?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="style.css" />
  <title>Patient Dashboard</title>
</head>

<body id="patient-page">

    <!-- Responsive Header -->
  <header class="top-header">
    <div class="header-left">
      <h2 id="header-title">🩺 MARS Admin Dashboard</h2>
      <span class="welcome-text" id="header-subtitle">
        Welcome Admin, <?php echo htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? '')); ?>!
      </span>
    </div>
  </header>

  <div id="main-page">
    <div class="container">
      <div class="overlay"></div>

      <!-- Hamburger -->
      <button class="hamburger" id="menu-btn">&#9776;</button>

      <!-- Sidebar -->
      <aside class="sidebar">
        <div class="profile-circle"></div>
        <ul class="nav">
          <li class="active" data-target="dashboard">Dashboard</li>
          <li data-target="caregiver">My Caregiver</li>
          <li data-target="appointments">Appointment Logs</li>
          <li data-target="notifications">Notifications</li>
          <li data-target="profile">Profile Information</li>
          <li id="logout-btn">Log out</li>
        </ul>
      </aside>

      <!-- Main Content -->
      <main class="main-content">

        <!-- Dashboard -->
<div id="dashboard" class="content-section active">
    <div class="cards">
        <div class="card">
            <span class="card-icon">📅</span>
            <div>
                <h2><?php echo $total_appointments; ?></h2>
                <p>Upcoming Appointments</p>
            </div>
        </div>
        <div class="card">
            <span class="card-icon">👩‍⚕️</span>
            <div>
                <h2><?php echo htmlspecialchars($current_caregiver); ?></h2>
                <p>Current Caregiver</p>
            </div>
        </div>
    </div>

    <!-- Upcoming Appointments Table or Placeholder -->
    <div id="upcoming-appointments" class="appointments-section">
        <h2>Upcoming Appointments</h2>
      <br></br>
        <?php if (!empty($upcoming_appointments)): ?>
            <table class="appointments-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Doctor</th>
                        <th>Caregiver</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($upcoming_appointments as $a): ?>
                        <tr>
                            <td><?= date("M d, Y h:i A", strtotime($a['appointment_date'])) ?></td>
                            <td><?= htmlspecialchars($a['doctor_name']) ?></td>
                            <td><?= htmlspecialchars($a['caregiver_name']) ?></td>
                            <td><span class="status <?= strtolower($a['status']) ?>"><?= ucfirst($a['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-appointments-placeholder">
                <h3>No Upcoming Appointments</h3>
                  <div class="no-appointments-placeholder">
                      <p>No appointments scheduled right now. Remember: good health is built day by day, so keep moving, eating well, and staying positive!</p>
                  </div>
            </div>

        <?php endif; ?>
    </div>
</div>

          <?php
$caregiver_info = null;

if ($current_caregiver !== "No caregiver assigned") {
    // Assuming caregiver_name is stored as "Firstname Lastname"
    $name_parts = explode(' ', $current_caregiver, 2);
    $first = $name_parts[0] ?? '';
    $last = $name_parts[1] ?? '';

    $stmt = $conn->prepare("
        SELECT id, surname, firstname, middle, birthdate, gender, contact, email, province, home_address, brgy_address, city_address
        FROM users
        WHERE firstname=? AND surname=?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $first, $last);
    $stmt->execute();
    $caregiver_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

?>


       <!-- My Caregiver -->
<!-- My Caregiver -->
<div id="caregiver" class="content-section">
    <h1>My Caregiver</h1>

    <?php if ($current_caregiver === "No caregiver assigned"): ?>
        <p>No caregiver assigned yet.</p>
    <?php else: ?>
        <div class="caregiver-table-wrapper">
            <table class="caregiver-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Birthdate</th>
                        <th>Gender</th>
                        <th>Email</th>
                        <th>Contact</th>
                        <th>Full Address</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <!-- Full Name -->
                        <td>
                            <?= htmlspecialchars(
                                trim(
                                    ($caregiver_info['firstname'] ?? '') . ' ' .
                                    ($caregiver_info['middle'] ?? '') . ' ' .
                                    ($caregiver_info['surname'] ?? '')
                                )
                            ) ?>
                        </td>

                        <!-- Birthdate -->
                        <td><?= htmlspecialchars($caregiver_info['birthdate'] ?? '') ?></td>

                        <!-- Gender -->
                        <td><?= htmlspecialchars($caregiver_info['gender'] ?? '') ?></td>

                        <!-- Email -->
                        <td><?= htmlspecialchars($caregiver_info['email'] ?? '') ?></td>

                        <!-- Contact -->
                        <td><?= htmlspecialchars($caregiver_info['contact'] ?? '') ?></td>

                        <!-- Full Address -->
                        <td>
                            <?= htmlspecialchars(
                                trim(
                                    ($caregiver_info['province'] ?? '') . ', ' .
                                    ($caregiver_info['city_address'] ?? '') . ', ' .
                                    ($caregiver_info['brgy_address'] ?? '') . ', ' .
                                    ($caregiver_info['home_address'] ?? '')
                                )
                            ) ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>




   <!-- Appointment Logs -->
        <div id="appointments" class="content-section">
    <h1>Appointment Logs</h1>

    <!-- Month Filter Form -->
  <form method="GET" action="" style="margin-bottom:20px;">
    <!-- Pass the active tab so it stays open after reload -->
    <input type="hidden" name="tab" value="appointments">
    
    <label>Select Month:</label>
    <input type="month" name="month" value="<?= isset($_GET['month']) ? $_GET['month'] : date('Y-m') ?>" />
    <button type="submit">Filter</button>
</form>


    <?php
    // --- Filter Appointments by Month ---
    $month_filter = $_GET['month'] ?? '';
    $appointments = [];

    if ($month_filter) {
        // Convert month input to start and end dates
        $start_date = date('Y-m-01', strtotime($month_filter));
        $end_date = date('Y-m-t 23:59:59', strtotime($month_filter));

        $stmt = $conn->prepare("
            SELECT appointment_id, appointment_date, status, doctor_name, caregiver_name
            FROM appointments
            WHERE patient_id = ? AND appointment_date BETWEEN ? AND ?
            ORDER BY appointment_date ASC
        ");
        $stmt->bind_param("iss", $user_id, $start_date, $end_date);
        $stmt->execute();
        $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        // Show all past appointments by default
        $appointments = $past_appointments;
    }
    ?>

    <table class="appointments-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Doctor</th>
                <th>Caregiver</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($appointments)): ?>
                <?php foreach ($appointments as $a): ?>
                <tr>
                    <td><?= date("M d, Y h:i A", strtotime($a['appointment_date'])) ?></td>
                    <td><?= htmlspecialchars($a['doctor_name']) ?></td>
                    <td><?= htmlspecialchars($a['caregiver_name']) ?></td>
                    <td><span class="status <?= strtolower($a['status']) ?>"><?= ucfirst($a['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align:center;">No appointments found for this month.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>



        <!-- Notifications -->
        <div id="notifications" class="content-section">
                <div style="margin-bottom:10px;">
                      <label><input type="checkbox" id="select-all"> Select All</label>
                      <button id="delete-selected">🗑 Delete Selected</button>
                </div>
                <div id="notifList" class="notif-cards">
                    <p>Loading notifications...</p>
                </div>
        </div>

         <div id="profile" class="content-section">
                    <div class="profile-box">
                        <div class="form-group">
                            <label>First Name:</label>
                            <input type="text" value="<?php echo $user['firstname'] ?? ''; ?>" readonly />
                        </div>
                        <div class="form-group">
                            <label>Middle Name:</label>
                            <input type="text" value="<?php echo $user['middle'] ?? ''; ?>" readonly />
                        </div>
                        <div class="form-group">
                            <label>Last Name:</label>
                            <input type="text" value="<?php echo $user['surname'] ?? ''; ?>" readonly />
                        </div>
                        <div class="form-group">
                            <label>Birthdate:</label>
                            <input type="text" value="<?php echo htmlspecialchars($user['birthdate']); ?>" readonly />
                        </div>
                        <div class="form-group">
                            <label>Gender:</label>
                            <input type="text" value="<?php echo htmlspecialchars($user['gender']); ?>" readonly />
                        </div>
                        <div class="form-group">
                            <label>Email:</label>
                            <input type="email" value="<?php echo $user['email'] ?? ''; ?>" readonly />
                        </div>
                        <div class="form-group">
                            <label>Contact:</label>
                            <input type="text" value="<?php echo $user['contact'] ?? ''; ?>" readonly />
                        </div>
                        <div class="form-group">
                            <label>Province:</label>
                            <input type="text" value="<?php echo $user['province'] ?? ''; ?>" readonly />
                        </div>
                        <div class="form-group">
                            <label>City Address:</label>
                            <input type="text" value="<?php echo $user['city_address'] ?? ''; ?>" readonly />
                        </div>
                        <div class="form-group">
                            <label>Barangay:</label>
                            <input type="text" value="<?php echo $user['brgy_address'] ?? ''; ?>" readonly />
                        </div>
                        <div class="form-group">
                            <label>Home Address:</label>
                            <input type="text" value="<?php echo $user['home_address'] ?? ''; ?>" readonly />
                        </div>
                    </div>
                </div>

      </main>
    </div>
  </div>

<!-- === DYNAMIC HEADER TEXT UPDATE (PUT THIS FIRST) === -->
<script>
// === Dynamic Header Text Update for Patient ===
const headerTitle = document.getElementById("header-title");
const headerSubtitle = document.getElementById("header-subtitle");

// Titles and subtitles for each Patient section
const sectionTitles = {
  dashboard: {
    title: "🏥 MARS Patient Dashboard",
    subtitle: "Welcome Patient, <?php echo htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? '')); ?>!"
  },
  medicine: {
    title: "💊 Medicine Schedule",
    subtitle: "View and manage your daily medicine reminders."
  },
  caregiver: {
    title: "🧑‍⚕️ My Caregiver",
    subtitle: "Request or check your assigned caregiver details."
  },
  appointments: {
    title: "📅 Appointment Logs",
    subtitle: "View your upcoming and past appointments with doctors."
  },
  notifications: {
    title: "🔔 Notifications Center",
    subtitle: "Check reminders, caregiver responses, and system updates."
  },
  profile: {
    title: "👤 Profile Information",
    subtitle: "View your personal and contact details."
  }
};

// 🟢 Set correct header when page reloads
const currentSection = "<?= $activeSection ?? 'dashboard' ?>";
if (sectionTitles[currentSection]) {
  headerTitle.textContent = sectionTitles[currentSection].title;
  headerSubtitle.textContent = sectionTitles[currentSection].subtitle;
}


// === Sidebar Navigation Click Events ===
const navItems = document.querySelectorAll(".sidebar .nav li");
const sections = document.querySelectorAll(".content-section");

navItems.forEach((item) => {
  item.addEventListener("click", () => {
    // Remove active states
    navItems.forEach(nav => nav.classList.remove("active"));
    sections.forEach(section => section.classList.remove("active"));

    // Add active to clicked
    item.classList.add("active");
    const targetSection = item.getAttribute("data-target");
    document.getElementById(targetSection).classList.add("active");

    // Update header dynamically
    if (sectionTitles[targetSection]) {
      headerTitle.textContent = sectionTitles[targetSection].title;
      headerSubtitle.textContent = sectionTitles[targetSection].subtitle;
    }

    // ✅ Clean URL
    if (window.history && window.history.replaceState) {
      const newUrl = window.location.pathname; 
      window.history.replaceState({}, document.title, newUrl);
    }
  });
});


document.addEventListener("DOMContentLoaded", () => {
  fetch('fetch_notifications.php')
    .then(res => res.json())
    .then(notifs => {
      const container = document.getElementById('notifList');
      if (!container) return;

      if (!notifs.length) {
        container.innerHTML = '<p>No notifications yet.</p>';
        return;
      }

      container.innerHTML = notifs.map(n => `
    <div class="notif-card ${n.is_read ? 'read' : 'unread'}">
        <label>
            <input type="checkbox" class="notif-checkbox" value="${n.id}">
            <span class="notif-message">${n.message}</span>
        </label>
        <div class="notif-meta">${new Date(n.created_at).toLocaleString()}</div>
    </div>
`).join('');

    })
    .catch(err => {
      console.error("Error fetching notifications:", err);
    });
});



document.addEventListener("DOMContentLoaded", () => {
    const selectAll = document.getElementById("select-all");
    const deleteBtn = document.getElementById("delete-selected");

    // ✅ Select All / Deselect All
    if (selectAll) {
        selectAll.addEventListener("change", () => {
            const checkboxes = document.querySelectorAll(".notif-checkbox");
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
        });
    }

    // ✅ Delete Selected Notifications
    if (deleteBtn) {
        deleteBtn.addEventListener("click", async () => {
            const checked = Array.from(document.querySelectorAll(".notif-checkbox:checked"));
            if (checked.length === 0) {
                alert("Please select at least one notification to delete.");
                return;
            }

            if (!confirm(`Are you sure you want to delete ${checked.length} notification(s)?`)) return;

            const ids = checked.map(cb => cb.value);

            try {
                const res = await fetch("Patient.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ action: "delete_notifications", ids })
                });

                const data = await res.json();
                if (data.status === "success") {
                    alert("Selected notifications deleted.");
                    // Remove deleted notifications from DOM
                    checked.forEach(cb => cb.closest(".notif-card").remove());
                    selectAll.checked = false;
                } else {
                    alert("Error deleting notifications: " + (data.message || "Unknown error"));
                }
            } catch (err) {
                console.error(err);
                alert("Error deleting notifications.");
            }
        });
    }
});



</script>

  <script>
  document.addEventListener("DOMContentLoaded", () => {
    const navItems = document.querySelectorAll("#patient-page .nav li:not(#logout-btn)");
    const sections = document.querySelectorAll("#patient-page .content-section");
    const logoutBtn = document.getElementById("logout-btn");

      function showTab(tab) {
  document.querySelectorAll('.appointment-tab').forEach(div => div.style.display = 'none');
  document.getElementById(tab).style.display = 'block';
}

    if (logoutBtn) {
      logoutBtn.addEventListener("click", () => {
        if (confirm("Are you sure you want to log out?")) {
          window.location.href = "index.php";
        }
      });
    }

    navItems.forEach((item, index) => {
      item.addEventListener("click", () => {
        navItems.forEach(nav => nav.classList.remove("active"));
        item.classList.add("active");
        sections.forEach(section => section.classList.remove("active"));
        if (sections[index]) sections[index].classList.add("active");
      });
    });

    const menuBtn = document.getElementById("menu-btn");
    const sidebar = document.querySelector("#patient-page .sidebar");
    const overlay = document.querySelector("#patient-page .overlay");

    menuBtn.addEventListener("click", () => {
      sidebar.classList.toggle("active");
      overlay.classList.toggle("active");
    });

    overlay.addEventListener("click", () => {
      sidebar.classList.remove("active");
      overlay.classList.remove("active");
    });
  });
  </script>
</body>
</html>
