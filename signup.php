<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'vendor/autoload.php';
require_once 'includes/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

session_start();

function generateVerificationToken() {
    return str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);
    $studentId = trim($_POST['student_id']);
    $course = trim($_POST['course']);
    $yearLevel = trim($_POST['year_level']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password']; // Get the confirm password 

    if (empty($firstName) || empty($lastName) || empty($studentId) || empty($course) || empty($yearLevel) || empty($email) || empty($password) || empty($confirmPassword)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif ($password!== $confirmPassword) { // Check if passwords match
        $error = "Passwords do not match.";
    } else {
        try {
            $query = $pdo->prepare("SELECT id FROM users WHERE email =?");
            $query->execute([$email]);
            
            if ($query->rowCount() > 0) {
                $error = "Email already registered.";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $verificationToken = generateVerificationToken();
                $tokenExpiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                $query = $pdo->prepare("INSERT INTO users 
                    (first_name, last_name, student_id, course, year_level, email, password, status, verification_token, token_expiry) 
                    VALUES (?,?,?,?,?,?,?, 'pending',?,?)");
                $query->execute([
                    $firstName, 
                    $lastName, 
                    $studentId,
                    $course,
                    $yearLevel,
                    $email, 
                    $hashedPassword,
                    $verificationToken,
                    $tokenExpiry
                ]);
                
                //... your existing email sending code...
                
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'libraryuclm@gmail.com';
            $mail->Password = 'wfjw idbq qtvm yzva';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('no-reply@yourlibrary.com', 'UCLM Library');
            $mail->addAddress($email);
            
            $mail->isHTML(true);
            $mail->Subject = 'Account Verification - UCLM Library';
            $mail->Body = "
                <h2>Welcome to UCLM Library!</h2>
                <p>Your verification code: <strong>$verificationToken</strong></p>
                <p>This code will expire in 24 hours. Please:</p>
                <ol>
                    <li>Visit the library front desk</li>
                    <li>Present this code</li>
                    <li>Have your ID card scanned</li>
                </ol>
            ";
            
            if(!$mail->send()) {
                throw new Exception('Email could not be sent. Error: ' . $mail->ErrorInfo);
            }


                header('Location: signup_success.php?email='. urlencode($email));
                exit;
            }
        } catch (Exception $e) {
            $error = "Registration failed: ". $e->getMessage();
        }
    }
}?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Library System</title>
    <link rel="stylesheet" href="css/login-styles.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1>Create Account</h1>
            <form method="POST">
                <div class="form-group">
                    <label for="first_name">First Name:</label>
                    <input type="text" name="first_name" required 
                        value="<?= htmlspecialchars($_POST['first_name']?? '')?>">
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name:</label>
                    <input type="text" name="last_name" required 
                        value="<?= htmlspecialchars($_POST['last_name']?? '')?>">
                </div>
                <div class="form-group">
                    <label for="student_id">Student ID:</label>
                    <input type="text" name="student_id" required 
                        value="<?= htmlspecialchars($_POST['student_id']?? '')?>">
                </div>
                <div class="form-group custom-dropdown">
                    <label for="course">Course/Strand:</label>
                    <select name="course" required>
                        <option value="STEM-Academic">STEM - Academic</option>
                        <option value="HUMSS">HUMSS</option>
                        <option value="ABM">ABM</option>
                        <option value="GAS">GAS</option>
                        <option value="STEM-Maritime">STEM - Maritime</option>
                        <option value="TVL">TVL</option>
                        <option value="BSIT">BS Information Technology</option>
                        <option value="BSCS">BS Computer Science</option>
                        <option value="BSIS">BS Information Systems</option>
                    </select>
                </div>
                <div class="form-group custom-dropdown">
                            <label for="year_level">Year Level:</label>
                            <select name="year_level" required>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                                <option value="11">Grade 11</option>
                                <option value="12">Grade 12</option> 
                            </select>
                        </div>
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" name="email" required 
                        value="<?= htmlspecialchars($_POST['email']?? '')?>">
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password:</label>
                    <input type="password" name="confirm_password" required>
                </div>
                
                <button type="submit">Sign Up</button>
            </form>
            <p class="text-center">Already have an account? <a href="index.php">Login here</a></p>
            <?php if ($error) echo "<p class='error'>$error</p>";?>
        </div>
    </div>
</body>
</html>