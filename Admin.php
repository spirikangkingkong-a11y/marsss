<?php
session_start();

$host = "localhost";
$user = "root";
$pass = "";
$db = "mars";
$activeSection = $_GET['section'] ?? $_POST['section'] ?? 'dashboard';
$conn = new mysqli($host, $user, $pass, $db);

$admin_id = $_SESSION['user_id'] ?? 0;

// Get total users
$userCount = 0;
$resultCount = $conn->query("SELECT COUNT(*) AS total FROM users");
if ($resultCount) {
    $rowCount = $resultCount->fetch_assoc();
    $userCount = $rowCount['total'];
}

// Count Doctors
$doctorCount = 0;
$resultDoctor = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'doctor'");
if ($resultDoctor) {
    $doctorCount = $resultDoctor->fetch_assoc()['total'];
}

// Count Caregivers
$caregiverCount = 0;
$resultCaregiver = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'caregiver'");
if ($resultCaregiver) {
    $caregiverCount = $resultCaregiver->fetch_assoc()['total'];
}

// Count Patients
$patientCount = 0;
$resultPatient = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'patient'");
if ($resultPatient) {
    $patientCount = $resultPatient->fetch_assoc()['total'];
}


if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Example: assume username stored in session
$current_user = $_SESSION['username'] ?? null;
$user = [];

if ($current_user) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $current_user);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $firstname    = $_POST['firstname'];
    $middle       = $_POST['middle'];
    $surname      = $_POST['surname'];
    $birthdate    = $_POST['birthdate'];
    $gender       = $_POST['gender'];
    $contact      = $_POST['contact'];
    $email        = $_POST['email'];
    $province     = $_POST['province'];
    $city_address = $_POST['city_address'];
    $brgy_address = $_POST['brgy_address'];
    $home_address = $_POST['home_address'];

    $update = $conn->prepare("UPDATE users SET 
        firstname = ?, middle = ?, surname = ?, birthdate = ?, gender = ?, contact = ?, 
        email = ?, province = ?, city_address = ?, brgy_address = ?, home_address = ?
        WHERE id = ?");
    $update->bind_param("sssssssssssi", 
        $firstname, $middle, $surname, $birthdate, $gender, $contact, 
        $email, $province, $city_address, $brgy_address, $home_address, $admin_id
    );

    if ($update->execute()) {
        echo "<script> alert('Profile updated successfully!'); window.location.href = 'Admin.php'; // or the page you want to go back to</script>";
    exit;
    } else {
         echo "<script>alert('Error updating profile!'); window.location.href = 'Admin.php';</script>";
    exit;
    }
    $update->close();
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="style.css" />
  <title>Admin Dashboard</title>
  <style>
    /* Sorting cursor + arrows */
    #users th {
      cursor: pointer;
      user-select: none;
    }
    #users th.asc::after {
      content: " ▲";
      font-size: 0.8em;
    }
    #users th.desc::after {
      content: " ▼";
      font-size: 0.8em;
    }
  </style>
</head>

<body id="admin-page">
  
  <!-- Responsive Header -->
  <header class="top-header">
    <div class="header-left">
      <h2 id="header-title">🩺 MARS Admin Dashboard</h2>
      <span class="welcome-text" id="header-subtitle"></span>
    </div>
  </header>


  <div id="main-page">
    <div class="container">
      <div class="overlay"></div>

      <button class="hamburger" id="menu-btn">&#9776;</button>

      <!-- Sidebar -->
<aside class="sidebar">
  <div class="profile-circle"></div>
  <ul class="nav">
    <li data-target="dashboard" class="<?= $activeSection === 'dashboard' ? 'active' : '' ?>">Dashboard</li>
    <li data-target="users" class="<?= $activeSection === 'users' ? 'active' : '' ?>">User Management</li>
    <li data-target="reports" class="<?= $activeSection === 'reports' ? 'active' : '' ?>">Reports</li>
    <li data-target="notifications" class="<?= $activeSection === 'notifications' ? 'active' : '' ?>">Notifications</li>
    <li data-target="profile" class="<?= $activeSection === 'profile' ? 'active' : '' ?>">Profile Information</li>
    <li id="logout-btn">Log out</li>
  </ul>
</aside>

      <!-- Main Content -->
      <main class="main-content">
        <!-- Dashboard -->
        <div id="dashboard" class="content-section <?= $activeSection === 'dashboard' ? 'active' : '' ?>">
          <div class="cards">
              <div class="card">
                <div class="card-icon">&#128101;</div>
                <div class="card-info">
                  <h3>Total Users</h3>
                  <p><?php echo $userCount; ?> Users</p>
                </div>
              </div>

              <div class="card">
                <div class="card-icon">👨‍⚕️</div>
                <div class="card-info">
                  <h3>Total Doctors</h3>
                  <p><?php echo $doctorCount; ?> Doctors</p>
                </div>
              </div>

              <div class="card">
                <div class="card-icon">🤝</div>
                <div class="card-info">
                  <h3>Total Caregivers</h3>
                  <p><?php echo $caregiverCount; ?> Caregivers</p>
                </div>
              </div>
           
              <div class="card">
                <div class="card-icon">🧑‍🦳</div>
                <div class="card-info">
                  <h3>Total Patients</h3>
                  <p><?php echo $patientCount; ?> Patients</p>
                </div>
              </div>
            
           <div class="chart-card">
                <h3>Monthly Appointment Chart</h3>
                <select id="yearFilter">
                    <option value="all">All Years</option>
                    <?php
                    $startYear = 2000; // Starting year
                    $currentYear = date('Y'); // Current year
                    for ($y = $startYear; $y <= $currentYear; $y++) {
                        // Select current year by default
                        $selected = ($y == $currentYear) ? 'selected' : '';
                        echo "<option value='$y' $selected>$y</option>";
                    }
                    ?>
                </select>

                <canvas id="appointmentChart"></canvas>
              </div>
          </div>
          </div>    

                  <!-- USER MANAGEMENT -->
                <div id="users" class="content-section user-management">
                   <div class="search-bar">
                      <input type="text" id="searchInput" placeholder="Search...">
                      <select id="filterType">
                        <option value="name">Name</option>
                        <option value="role">Role</option>
                        <option value="email">Email</option>
                      </select>
                    </div>



                <table id="userTable">
                  <thead>
                    <tr>
                      <th>Full Name</th>
                      <th>Email</th>
                      <th>Role</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                   <?php
                          // Make sure the session is started and $conn + $user are available
                          // $user should contain the currently logged-in user's data

                          $result = $conn->query("SELECT id, firstname, surname, email, role FROM users");

                          if ($result && $result->num_rows > 0) {
                              while ($row = $result->fetch_assoc()) {
                                  // Compare each user ID to the logged-in user's ID
                                  $status = ($row['id'] == ($user['id'] ?? 0)) ? "Active" : "Offline";
                                  $statusClass = strtolower($status);

                                  echo "<tr>";
                                  echo "<td>" . htmlspecialchars($row['firstname'] . " " . $row['surname']) . "</td>";
                                  echo "<td>" . htmlspecialchars($row['email']) . "</td>";
                                  echo "<td>" . htmlspecialchars(ucfirst($row['role'])) . "</td>";
                                  echo "<td><span class='status $statusClass'>$status</span></td>";
                                  echo "</tr>";
                              }
                          } else {
                              echo "<tr><td colspan='4'>No users found</td></tr>";
                          }
                          ?>
                  </tbody>
                </table>
              </div>



      
<!-- Reports -->
<div id="reports" class="content-section <?= $activeSection === 'reports' ? 'active' : '' ?>">
 <form method="GET">
  <input type="hidden" name="section" value="reports">

  <!-- 🔹 TOP FILTERS: PEOPLE SELECTION -->
  <div class="filter-group">
    <!-- Doctor Filter -->
    <label for="doctor">Select Doctor:</label>
    <select name="doctor_id" id="doctor">
      <option value="">-- Choose Doctor --</option>
      <?php
      $doc_result = $conn->query("SELECT id, firstname, surname FROM users WHERE role = 'doctor'");
      while ($doc = $doc_result->fetch_assoc()):
      ?>
        <option value="<?= $doc['id'] ?>" <?= (($_GET['doctor_id'] ?? '') == $doc['id']) ? 'selected' : '' ?>>
          Dr. <?= htmlspecialchars($doc['firstname'] . " " . $doc['surname']) ?>
        </option>
      <?php endwhile; ?>
    </select>

    <!-- Caregiver Filter -->
    <label for="caregiver">Select Caregiver:</label>
    <select name="caregiver_id" id="caregiver">
      <option value="">-- Choose Caregiver --</option>
      <?php
      $care_result = $conn->query("SELECT id, firstname, surname FROM users WHERE role = 'caregiver'");
      while ($care = $care_result->fetch_assoc()):
      ?>
        <option value="<?= $care['id'] ?>" <?= (($_GET['caregiver_id'] ?? '') == $care['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($care['firstname'] . " " . $care['surname']) ?>
        </option>
      <?php endwhile; ?>
    </select>

    <!-- Patient Filter -->
    <label for="patient">Select Patient:</label>
    <select name="patient_id" id="patient">
      <option value="">-- Choose Patient --</option>
      <?php
      $pat_result = $conn->query("SELECT id, firstname, surname FROM users WHERE role = 'patient'");
      while ($pat = $pat_result->fetch_assoc()):
      ?>
        <option value="<?= $pat['id'] ?>" <?= (($_GET['patient_id'] ?? '') == $pat['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($pat['firstname'] . " " . $pat['surname']) ?>
        </option>
      <?php endwhile; ?>
    </select>
  </div>

  <hr>

  <!-- 🔹 BOTTOM FILTERS: DATE SELECTION -->
  <div class="filter-group">
    <label for="month">Select Month:</label>
    <input type="month" name="month" id="month" value="<?= $_GET['month'] ?? date('Y-m') ?>">

    <label for="exact_date">Or Select Exact Date:</label>
    <input type="date" name="exact_date" id="exact_date" value="<?= $_GET['exact_date'] ?? '' ?>">

    <button type="submit">🔍 Filter</button>
    <button type="button" onclick="printReports()">🖨️ Print</button>
  </div>
</form>


  <br>

  <div id="reportSection">
    <table class="datagrid">
      <thead>
        <tr>
          <th>Appointment ID</th>
          <th>Doctor</th>         
          <th>Caregiver</th>
          <th>Patient</th>
          <th>Date</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $filters = [];
        $params = [];
        $types = "";

        // Apply filters dynamically
        if (!empty($_GET['doctor_id'])) {
          $filters[] = "a.doctor_id = ?";
          $params[] = intval($_GET['doctor_id']);
          $types .= "i";
        }
        if (!empty($_GET['caregiver_id'])) {
          $filters[] = "a.caregiver_id = ?";
          $params[] = intval($_GET['caregiver_id']);
          $types .= "i";
        }
        if (!empty($_GET['patient_id'])) {
          $filters[] = "a.patient_id = ?";
          $params[] = intval($_GET['patient_id']);
          $types .= "i";
        }
        // Exact Date
        if (!empty($_GET['exact_date'])) {
          $filters[] = "DATE(a.appointment_date) = ?";
          $params[] = $_GET['exact_date'];
          $types .= "s";
        }
        // Month Filter
        elseif (!empty($_GET['month'])) {
          $filters[] = "DATE_FORMAT(a.appointment_date, '%Y-%m') = ?";
          $params[] = $_GET['month'];
          $types .= "s";
        }

        if (!empty($filters)) {
          $sql = "
            SELECT a.appointment_id, a.appointment_date, a.status,
                  a.doctor_id, a.caregiver_id, a.patient_id,
                  d.firstname AS doc_first, d.surname AS doc_last,
                  c.firstname AS care_first, c.surname AS care_last,
                  p.firstname AS pat_first, p.surname AS pat_last
            FROM appointments a
            JOIN users d ON d.id = a.doctor_id
            JOIN users c ON c.id = a.caregiver_id
            JOIN users p ON p.id = a.patient_id
            WHERE " . implode(" AND ", $filters) . "
            ORDER BY a.appointment_date ASC
          ";

          $stmt = $conn->prepare($sql);
          if ($types) $stmt->bind_param($types, ...$params);
          $stmt->execute();
          $result = $stmt->get_result();

          if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
              // ✅ Highlight Logic
              $highlight = '';
              if (
                (!empty($_GET['doctor_id']) && intval($_GET['doctor_id']) == intval($row['doctor_id'])) ||
                (!empty($_GET['caregiver_id']) && intval($_GET['caregiver_id']) == intval($row['caregiver_id'])) ||
                (!empty($_GET['patient_id']) && intval($_GET['patient_id']) == intval($row['patient_id'])) ||
                (!empty($_GET['month']) && strpos($row['appointment_date'], $_GET['month']) !== false) ||
                (!empty($_GET['exact_date']) && $_GET['exact_date'] == substr($row['appointment_date'], 0, 10))
              ) {
                $highlight = 'highlight';
              }

              echo "<tr class='$highlight'>";
              echo "<td>" . htmlspecialchars($row['appointment_id']) . "</td>";
              echo "<td>Dr. " . htmlspecialchars($row['doc_first'] . " " . $row['doc_last']) . "</td>";
              echo "<td>" . htmlspecialchars($row['care_first'] . " " . $row['care_last']) . "</td>";
              echo "<td>" . htmlspecialchars($row['pat_first'] . " " . $row['pat_last']) . "</td>";
              echo "<td>" . htmlspecialchars($row['appointment_date']) . "</td>";
              echo "<td><span class='status " . strtolower($row['status']) . "'>" . ucfirst($row['status']) . "</span></td>";
              echo "</tr>";
            }
          } else {
            echo "<tr><td colspan='6'>No records found for the selected filters.</td></tr>";
          }

          $stmt->close();
        } else {
          echo "<tr><td colspan='6'>Please select at least one filter to view reports.</td></tr>";
        }
        ?>
        </tbody>

    </table>
  </div>
</div>



      <!-- Notifications -->         
                  <div id="notifications" class="content-section <?= $activeSection === 'notifications' ? 'active' : '' ?>">
                    <table class="datagrid">
                      <thead>
                        <tr>
                          <th>Role</th>
                          <th>Name</th>
                          <th>Date Registered</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php
                        $users_sql = "SELECT firstname, surname, role, created_at 
                                      FROM users 
                                      ORDER BY created_at DESC LIMIT 5";
                        $users_result = $conn->query($users_sql);

                        if ($users_result && $users_result->num_rows > 0) {
                            while ($u = $users_result->fetch_assoc()) {
                                echo "<tr>";
                                echo "<td>".ucfirst($u['role'])."</td>";
                                echo "<td>".htmlspecialchars($u['firstname']." ".$u['surname'])."</td>";
                                echo "<td>".date("M d, Y", strtotime($u['created_at']))."</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='3'>No recent registrations.</td></tr>";
                        }
                        ?>
                      </tbody>
                    </table>
                  </div>  
            </div>
           </div>


         <!-- Profile -->
                <div id="profile" class="content-section">
  <div class="profile-box">
    <form method="POST" id="profileForm">

      <div class="form-group">
        <label>First Name:</label>
        <input type="text" name="firstname" value="<?php echo $user['firstname'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Middle Name:</label>
        <input type="text" name="middle" value="<?php echo $user['middle'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Last Name:</label>
        <input type="text" name="surname" value="<?php echo $user['surname'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Birthdate:</label>
        <input type="date" name="birthdate" value="<?php echo $user['birthdate'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Gender:</label>
        <input type="text" name="gender" value="<?php echo $user['gender'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Email:</label>
        <input type="email" name="email" 
              value="<?php echo $user['email'] ?? ''; ?>" 
              readonly 
              required 
              pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$" 
              title="Please enter a valid email address">
      </div>

      <div class="form-group">
        <label>Contact:</label>
        <input type="text" name="contact" 
              value="<?php echo $user['contact'] ?? ''; ?>" 
              readonly 
              required 
              pattern="\d{10,11}" 
              title="Contact must be 10 or 11 digits only">
      </div>

      <div class="form-group">
        <label>Province:</label>
        <input type="text" name="province" value="<?php echo $user['province'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>City Address:</label>
        <input type="text" name="city_address" value="<?php echo $user['city_address'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Barangay:</label>
        <input type="text" name="brgy_address" value="<?php echo $user['brgy_address'] ?? ''; ?>" readonly>
      </div>

      <div class="form-group">
        <label>Home Address:</label>
        <input type="text" name="home_address" value="<?php echo $user['home_address'] ?? ''; ?>" readonly>
      </div>

      <!-- Make buttons full width (span 2 columns) -->
      <div id="profile-buttons">
        <button type="button" id="editBtn">Edit</button>
        <button type="submit" name="save_profile" id="saveBtn" style="display:none;">Save</button>
        <button type="button" id="cancelBtn" disabled>Cancel</button>
      </div>
    </form>
  </div>
</div>

      </main>
    </div>
  </div>

<script>
document.addEventListener("DOMContentLoaded", () => {

  const editBtn = document.getElementById("editBtn");
  const cancelBtn = document.getElementById("cancelBtn");
  const saveBtn = document.getElementById("saveBtn");
  const profileForm = document.getElementById("profileForm");
  const contactInput = document.querySelector('input[name="contact"]');

  // Only allow numbers and max 11 digits in contact
  contactInput.addEventListener('input', function() {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0,11);
  });

  // Edit button click
  editBtn.addEventListener("click", () => {
      profileForm.querySelectorAll("input").forEach(input => {
          input.removeAttribute("readonly");
          input.disabled = false;
      });

      saveBtn.style.display = "inline-block";
      cancelBtn.disabled = false;
      editBtn.disabled = true;
  });

  // Cancel button click
  cancelBtn.addEventListener("click", () => {
      location.reload(); // Reload page to reset form
  });

});
</script>



<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- ✅ Place the tab JavaScript here -->
<script>
  // Tab switching logic
  document.addEventListener("DOMContentLoaded", () => {
    const navItems = document.querySelectorAll("#admin-page .nav li");
    const sections = document.querySelectorAll("#admin-page .content-section");

    navItems.forEach(item => {
      item.addEventListener("click", () => {
        // Remove active from all
        navItems.forEach(nav => nav.classList.remove("active"));
        sections.forEach(section => section.classList.remove("active"));

        // Add active to clicked section
        item.classList.add("active");
        const target = item.getAttribute("data-target");
        document.getElementById(target).classList.add("active");
      });
    });
  });
</script>
  <script>
document.addEventListener("DOMContentLoaded", () => {
  const navItems = document.querySelectorAll("#admin-page .nav li");
  const sections = document.querySelectorAll("#admin-page .content-section");
  const logoutBtn = document.getElementById("logout-btn");
 

     const ctx = document.getElementById('appointmentChart');

fetch('fetch_appointments_data.php')
  .then(response => response.json())
  .then(chartData => {
    const chart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: chartData.labels,
        datasets: [{
          label: 'Appointments',
          data: chartData.data,
          borderColor: '#4f46e5',
          backgroundColor: 'rgba(79,70,229,0.1)',
          fill: true,
          tension: 0.4,
          pointBackgroundColor: '#4f46e5'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: true },
          tooltip: { mode: 'index', intersect: false }
        },
        scales: {
          y: { beginAtZero: true }
        }
      }
    });

    // 🎯 YEAR FILTER LOGIC
    const yearFilter = document.getElementById("yearFilter");
    yearFilter.addEventListener("change", () => {
  const selectedYear = yearFilter.value;

      fetch(`fetch_appointments_data.php?year=${selectedYear}`)
        .then(res => res.json())
        .then(filteredData => {
          chart.data.labels = filteredData.labels;
          chart.data.datasets[0].data = filteredData.data;
          chart.data.datasets[0].label = `Appointments (${selectedYear})`;
          chart.update();
          
        });
    });

  })
  .catch(err => console.error('Error fetching appointment data:', err));


  // Logout confirmation
  if (logoutBtn) {
    logoutBtn.addEventListener("click", () => {
      if (confirm("Are you sure you want to log out?")) {
        window.location.href = "index.php";
      }
    });
  }

  // Sidebar navigation
  navItems.forEach((item, index) => {
    item.addEventListener("click", () => {
      navItems.forEach(nav => nav.classList.remove("active"));
      item.classList.add("active");
      sections.forEach(section => section.classList.remove("active"));

      if (index === 0) document.getElementById("dashboard").classList.add("active");
      if (index === 1) document.getElementById("users").classList.add("active");
      if (index === 2) document.getElementById("reports").classList.add("active");
      if (index === 3) document.getElementById("notifications").classList.add("active");
      if (index === 4) document.getElementById("profile").classList.add("active");
    });
  });

    // === Dynamic Header Text Update ===
  const headerTitle = document.getElementById("header-title");
  const headerSubtitle = document.getElementById("header-subtitle");

  const sectionTitles = {
    dashboard: {
      title: "🩺 MARS Admin Dashboard",
      subtitle: "Welcome Admin, <?php echo htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['surname'] ?? '')); ?>!"
    },
    users: {
      title: "👥 User Management",
      subtitle: "Manage registered users and their access levels."
    },
    reports: {
      title: "📊 Reports Overview",
      subtitle: "View and print appointment statistics and summaries."
    },
    notifications: {
      title: "🔔 Notifications Center",
      subtitle: "Check recent alerts and system updates."
    },
    profile: {
      title: "👤 Profile Information",
      subtitle: "View and manage your personal account details."
    }
  };

  // 🟢 Fix: Set correct header when page reloads (like after filter)
  const currentSection = "<?= $activeSection ?>";
  if (sectionTitles[currentSection]) {
    headerTitle.textContent = sectionTitles[currentSection].title;
    headerSubtitle.textContent = sectionTitles[currentSection].subtitle;
  }

  // When clicking a nav item → update header text dynamically
 // Sidebar navigation
navItems.forEach((item, index) => {
  item.addEventListener("click", () => {
    navItems.forEach(nav => nav.classList.remove("active"));
    item.classList.add("active");
    sections.forEach(section => section.classList.remove("active"));

    let targetSection = item.getAttribute("data-target");
    document.getElementById(targetSection).classList.add("active");

    // Update header dynamically
    if (sectionTitles[targetSection]) {
      headerTitle.textContent = sectionTitles[targetSection].title;
      headerSubtitle.textContent = sectionTitles[targetSection].subtitle;
    }

    // ✅ Remove ?section=... from URL so refresh stays in correct section
    if (window.history && window.history.replaceState) {
      const newUrl = window.location.pathname; // just 'admin.php'
      window.history.replaceState({}, document.title, newUrl);
    }
  });
});

  // Sidebar toggle
  const menuBtn = document.getElementById("menu-btn");
  const sidebar = document.querySelector("#admin-page .sidebar");
  const mainContent = document.querySelector("#admin-page .main-content");
  const overlay = document.querySelector("#admin-page .overlay");

  menuBtn.addEventListener("click", () => {
    sidebar.classList.toggle("active");
    overlay.classList.toggle("active");
  });

  [ ...navItems, mainContent, overlay ].forEach(el => {
    el.addEventListener("click", () => {
      sidebar.classList.remove("active");
      overlay.classList.remove("active");
    });
  });

  // === Table Sorting ===
  const table = document.querySelector("#userTable"); // your DataGridView table
  if (table) {
    const headers = table.querySelectorAll("th");
    headers.forEach((header, index) => {
      header.addEventListener("click", () => {
        const tbody = table.querySelector("tbody");
        const rows = Array.from(tbody.querySelectorAll("tr"));
        const isAsc = header.classList.contains("asc");

        headers.forEach(h => h.classList.remove("asc", "desc"));
        header.classList.toggle("asc", !isAsc);
        header.classList.toggle("desc", isAsc);

        rows.sort((a, b) => {
          const aText = a.children[index].textContent.trim().toLowerCase();
          const bText = b.children[index].textContent.trim().toLowerCase();
          return isAsc ? bText.localeCompare(aText) : aText.localeCompare(bText);
        });

        rows.forEach(row => tbody.appendChild(row));
      });
    });
  }

 const searchInput = document.getElementById("searchInput");
const filterType = document.getElementById("filterType");

if (searchInput && filterType && table) {
  const applyFilter = () => {
    const filter = searchInput.value.toLowerCase();
    const rows = table.querySelectorAll("tbody tr");

    rows.forEach(row => {
      const cells = row.querySelectorAll("td");
      let match = false;

      if (filterType.value === "all") {
        match = Array.from(cells).some(cell =>
          cell.textContent.toLowerCase().startsWith(filter)
        );
      } else if (filterType.value === "name") {
        match = cells[0].textContent.toLowerCase().startsWith(filter); // Name
      } else if (filterType.value === "email") {
        match = cells[1].textContent.toLowerCase().startsWith(filter); // Email
      } else if (filterType.value === "role") {
        match = cells[2].textContent.toLowerCase().startsWith(filter); // Role
      }

      row.style.display = match ? "" : "none";
    });
  };

  // When typing in search
  searchInput.addEventListener("input", applyFilter);

  // When changing filter dropdown → reapply filter with existing search text
  filterType.addEventListener("change", applyFilter);
}


});

function printReports() {
  const reportContent = document.getElementById("reportSection").innerHTML;
  const printWindow = window.open("", "", "width=900,height=650");

  const now = new Date();
  const formattedDate = now.toLocaleString("en-US", { 
    year: "numeric", 
    month: "long", 
    day: "numeric", 
    hour: "numeric", 
    minute: "2-digit", 
    second: "2-digit", 
    hour12: true   // 👈 forces AM/PM
  });


  printWindow.document.write(`
  <html>
    <head>
      <title>Appointment Reports</title>
      <style>
        body {
          font-family: Poppins, sans-serif;
          padding: 20px;
          color: #111;
        }

        table {
          width: 100%;
          border-collapse: collapse;
          margin-top: 15px;
        }

        th, td {
          padding: 10px;
          border: 1px solid #3108b8ff;
          text-align: left;
        }

        /* ✅ Ensures blue header always prints as blue */
        thead th {
          background: #4f46e5 !important;
          color: #fff !important;
          -webkit-print-color-adjust: exact !important;
          print-color-adjust: exact !important;
        }

        tr:nth-child(even) {
          background: #f9fafb;
        }

        .status {
          padding: 5px 10px;
          border-radius: 6px;
          color: #040404ff;
          font-size: 0.85em;
          font-weight: bold;
        }

        .status.pending { background: #f59e0b; }
        .status.approved { background: #10b981; }
        .status.cancelled { background: #ef4444; }

        @media print {
          body {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
          }
        }
      </style>
    </head>
    <body>
      <h2 style="color:#1e3a8a;">Appointment Reports</h2>
      <p style="text-align:right; font-size:0.9em; color:#555;">Generated on: ${formattedDate}</p>
      ${reportContent}
    </body>
  </html>
`);

  printWindow.document.close();
  printWindow.print();
}

</script>

  <?php $conn->close(); ?>
</body>
</html>
