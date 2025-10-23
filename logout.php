<?php
session_start();
$tab_id = $_GET['tab_id'] ?? 'default';
if(isset($_SESSION['users'][$tab_id])){
    unset($_SESSION['users'][$tab_id]);
}
header("Location: index.php");
exit;
?>
