// Select elements
const signupModal = document.getElementById("signupModal");
const loginModal = document.getElementById("loginModal");


const openSignup = document.getElementById("openSignup");
const openLogin = document.getElementById("openLogin");
const getStarted = document.getElementById("getStarted");


const closeSignup = document.getElementById("closeSignup");
const closeLogin = document.getElementById("closeLogin");

// Open modals
openSignup.onclick = () => signupModal.style.display = "flex";
openLogin.onclick = () => loginModal.style.display = "flex";

// Close modals
closeSignup.onclick = () => signupModal.style.display = "none";
closeLogin.onclick = () => loginModal.style.display = "none";

// Close when clicking outside modal content
window.onclick = (event) => {
  if (event.target === signupModal) signupModal.style.display = "none";
  if (event.target === loginModal) loginModal.style.display = "none";
}
