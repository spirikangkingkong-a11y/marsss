<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="style.css" />
  <title>Medicine Reminder</title>
</head>
<body id="front-page">

  <!-- Header with Boxed Navigation -->
  <header>
    <div class="logo">💊MedReminder</div>
    <nav>
      <ul>
        <li><a href="#home">Home</a></li>
        <li><a href="#about">About</a></li>
        <li><a href="#" id="openSignup">Sign Up</a></li>
        <li><a href="#" id="openLogin">Log In</a></li>
      </ul>
    </nav>
  </header>

  
  <!-- Hero Section -->
  <section class="hero" id="home">
    <div class="hero-text">
      <h1>Never Miss a Dose Again</h1>
      <p>Stay on track with your medications. Our medicine reminder helps you organize schedules, get timely alerts to manage your health with ease.</p>

    </div>
    <img src="Image/mainImage.jfif" alt="Pic" class="Pic">
  </section>

  <!-- About Section -->
  <section class="features" id="about">
    <h2>Why Choose Our Reminder?</h2>
    <div class="feature-list">
      <div class="feature">
        <h3>⏰ Smart Alerts</h3>
        <p>Get timely reminders for your medicines, vitamins, or supplements.</p>
      </div>
      <div class="feature">
        <h3>📋 Easy Tracking</h3>
        <p>Track dosage history and never miss an important schedule.</p>
      </div>
      <div class="feature">
        <h3>📱 User Friendly</h3>
        <p>Simple, clean, and accessible design for everyone.</p>
      </div>
    </div>
  </section>

  <!-- ✅ Sign Up Modal -->
  <div id="signupModal" class="modal">
    <div class="modal-content">
      <div id="signup-page"> 
        <span class="close" id="closeSignup">&times;</span>
        <?php include('signup.php'); ?>

         <!-- Form Box FOR SIGN UP -->
    <div class="form-box">
        <div class="header">
            <img src="Image/Logo.jpg" alt="Logo" class="logo">
            <h2>Welcome!!!</h2>
            <h1>Create Account</h1>
        </div>

        <form id="signup-form">
   <div class="form-group">
        <input type="text" name="username" id="username" placeholder="Username" required />
    </div>

 
  <div class="password-wrapper">
      <input type="password" name="password" id="password" class="password-input" placeholder="Password" required />
      <button type="button" class="toggle-password">👁</button>
  </div>


    <div class="row">
        <select name="role" id="role" required>
            <option value="" disabled selected>Select Role</option>
            <option value="Doctor">Doctor</option>
            <option value="Caregiver">Caregiver</option>
            <option value="Patient">Patient</option>
        </select>
    </div>

    <div class="terms">
        <label>
            <input type="checkbox" name="terms" id="terms" required />
            <span>Agree to Terms and Policy?</span>
        </label>
    </div>

    <div class="buttons">
        <button type="submit" class="signup">Sign Up</button>
        <button type="button" class="exit" onclick="window.location.href='main.php'">Exit</button>
    </div>
</form>

    </div>

        
      </div>
    </div>
  </div>

  <!-- ✅ Log In Modal -->
  <div id="loginModal" class="modal">
    <div class="modal-content">
      <div id="login-page"> 
        <span class="close" id="closeLogin">&times;</span>
        <?php include('login.php'); ?>
        
         <!-- Form Section FOR LOG IN -->
    <div class="form-section">
      <form action="login.php" method="POST">
        <img src="Image/Logo.jpg" alt="Logo" style="height: 80px; margin-bottom: 10px;">
        <p>Welcome back !!!</p>
        <h2>Log In</h2>

        <!-- Username -->
        <div class="form-group">
          <input type="text" name="username" id="email" placeholder="Username" required>
        </div>

            <div class="form-group password-group">
                <input type="password" name="password" class="password-input" placeholder="Password" required>
                <span class="toggle-password">👁</span>
            </div>


        <!-- Role -->
        <div class="role">
          <select name="role" id="role" required>
            <option value="" disabled selected>Select Role</option>
            <option value="Admin">Admin</option>
            <option value="Doctor">Doctor</option>
            <option value="Caregiver">Caregiver</option>
            <option value="Patient">Patient</option>            
          </select>
        </div>

        <!-- Submit Button -->
        <button class="btn" type="submit">LOG IN</button>
        
      </form>
    </div>
      </div>
    </div>
  </div>

  <script src="script.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("signup-form");

document.addEventListener("DOMContentLoaded", function () {
    // Universal password toggle for any form
    document.querySelectorAll(".toggle-password").forEach(toggleBtn => {
        toggleBtn.addEventListener("click", function() {
            // Find the related input
            const pwdInput = this.closest(".password-wrapper, .password-group").querySelector(".password-input");
            if(pwdInput.type === "password") {
                pwdInput.type = "text";
                this.textContent = "🙈";
            } else {
                pwdInput.type = "password";
                this.textContent = "👁";
            }
        });
    });
});

    // Form submit
    form.addEventListener("submit", function(e){
        e.preventDefault();

        const requiredFields = [
           "role","username","password"
        ];

        let hasError = false;

        // Reset previous error styles
        requiredFields.forEach(field => {
            const input = document.getElementById(field);
            if(input) input.style.border = "";
        });

        // Check required fields
        for(let field of requiredFields){
            let input = document.getElementById(field);
            if(!input || input.value.trim() === ""){
                input.style.border = "2px solid red"; // Highlight missing field
                input.focus();
                hasError = true;
                break;
            }
        }

        if(hasError) return;

        // Validate terms checkbox
        const termsChecked = document.getElementById("terms").checked;
        if(!termsChecked){
            alert("You must agree to the terms.");
            return;
        }

        // Submit form via fetch
        const formData = new FormData(form);
        fetch("signup.php", { method: "POST", body: formData })
            .then(res => res.text())
            .then(data => {
                console.log(data);
                if(data.includes("Success")){
                    alert("Account created successfully!");
                    form.reset();
                    window.location.href = "main.php";
                } else {
                    alert("Signup failed: " + data);
                }
            })
            .catch(err => { 
                console.error(err); 
                alert("Something went wrong."); 
            });
    });
});
</script>


<script>

    if (!sessionStorage.getItem('tab_id')) {
    sessionStorage.setItem('tab_id', Date.now() + Math.random());
}

// Send tab_id with the form
document.querySelector('form').addEventListener('submit', function() {
    let input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'tab_id';
    input.value = sessionStorage.getItem('tab_id');
    this.appendChild(input);
});


  document.addEventListener("DOMContentLoaded", function () {
    // Universal password toggle for any form
    document.querySelectorAll(".toggle-password").forEach(toggleBtn => {
        toggleBtn.addEventListener("click", function() {
            // Find the related input
            const pwdInput = this.closest(".password-wrapper, .password-group").querySelector(".password-input");
            if(pwdInput.type === "password") {
                pwdInput.type = "text";
                this.textContent = "🙈";
            } else {
                pwdInput.type = "password";
                this.textContent = "👁";
            }
        });
    });
});

</script>

</body>
</html>
