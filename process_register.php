<?php
// Simple database connection
$serverName = "tcp:mycardiffmet.database.windows.net, 1433"; 
$connectionOptions = array(
    "Database" => "myDatabase",
    "Uid" => "myadmin",
    "PWD" => "Abcdefgh0!",
    "Encrypt" => 1,
    "TrustServerCertificate" => 0
);

// Process form submission 
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = $_POST['password'];

    // Basic validation 
    if (!empty($name) && !empty($email) && !empty($password)) {
        // Connect to database
        $conn = sqlsrv_connect($serverName, $connectionOptions);
        if ($conn) {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            // Insert into database
            $sql = "INSERT INTO shopusers (name, email, password) VALUES (?, ?, ?)";
            $params = array($name, $email, $hashed_password); 
            $stmt = sqlsrv_query($conn, $sql, $params);

            if ($stmt) {
                // Redirect to success page 
                header("Location: success.php"); 
                exit();
            } else {
                // Get detailed error information
                $errors = sqlsrv_errors();
                $error_message = "Database error: "; 
                if ($errors != null) { 
                    foreach ($errors as $error) {
                        $error_message .= "SQLSTATE: ".$error['SQLSTATE'].", ";
                        $error_message .= "Code: ". $error['code'].", ";
                        $error_message .= "Message: ".$error['message'];
                    }
                }
                // Redirect back with detailed error
                header("Location: register.html?error=".urlencode($error_message));
                exit();
            }
        } else {
            sqlsrv_free_stmt($stmt); sqlsrv_close($conn);
            $connection_errors = sqlsrv_errors();
            $conn_error_message = "Database connection failed: "; 
            if ($connection_errors != null) { 
                foreach ($connection_errors as $error) {
                    $conn_error_message .= $error['message'];
                }
            }
            header("Location: register.html?error=". urlencode($conn_error_message)); 
            exit();
        } 
    } else {
        header("Location: register.html?error=Please+fill+all+fields"); 
        exit();
    }
} else {
    // If someone tries to access this page directly 
    header("Location: register.html"); 
    exit();
}
?>