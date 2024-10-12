<?php

session_start();

// MySQL database connection (replace with your own credentials)
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "reservesphp";

// DSA private and public key paths
$privateKeyFile = "dsa-private.pem";
$publicKeyFile = "dsa-public.pem";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle signup and set session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup_username'])) {
    $newUsername = $_POST['signup_username'];

    // Check if username exists
    $userCheck = $conn->query("SELECT * FROM members WHERE username = '$newUsername'");
    if ($userCheck->num_rows == 0) {
        // Insert new user
        $conn->query("INSERT INTO members (username) VALUES ('$newUsername')");
        $_SESSION['username'] = $newUsername;
        echo "User $newUsername created successfully!";
    } else {
        echo "Username $newUsername already exists!";
    }
}

// If a user is logged in, retrieve session username
$sessionUsername = isset($_SESSION['username']) ? $_SESSION['username'] : '';

// Handle file upload and blockchain storage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && isset($_POST['file_username'])) {
    $file = $_FILES['file'];
    $fileContent = file_get_contents($file['tmp_name']);
    $fileName = $file['name'];
    $fileUsername = $_POST['file_username'];  // Reward the correct user

    if (!isset($_FILES['signature_file']) || empty($_FILES['signature_file']['tmp_name'])) {
        // First time file upload, sign the file using DSA private key
        if (file_exists($privateKeyFile)) {
            // Load the DSA private key
            $privateKey = openssl_pkey_get_private(file_get_contents($privateKeyFile));

            // Hash the file content
            $fileHash = hash('sha256', $fileContent);

            // Sign the file hash using DSA
            openssl_sign($fileHash, $signature, $privateKey, OPENSSL_ALGO_DSS1);

            // Create the .signature file with the DSA public key for verification
            $signatureFile = fopen("$fileName.signature", "w");
            fwrite($signatureFile, "$fileHash\n" . base64_encode($signature) . "\n" . file_get_contents($publicKeyFile));
            fclose($signatureFile);

            // Prompt for digital signature download
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename("$fileName.signature") . '"');
            readfile("$fileName.signature");

            // Stop further execution to prevent HTML output
            exit;

        } else {
            echo "DSA private key not found.";
        }

    } elseif (isset($_FILES['signature_file']) && !empty($_FILES['signature_file']['tmp_name'])) {
        // Second time file upload with signature verification
        $signatureFileContent = file_get_contents($_FILES['signature_file']['tmp_name']);

        // Parse the signature file
        $signatureFileLines = explode("\n", $signatureFileContent);
        if (count($signatureFileLines) >= 3) {
            $originalHmac = trim($signatureFileLines[0]);
            $originalSignature = base64_decode(trim($signatureFileLines[1]));
            $publicKey = trim(implode("\n", array_slice($signatureFileLines, 2)));

            // Hash the new file content
            $newHmac = hash('sha256', $fileContent);

            // Verify the signature using the DSA public key
            $publicKey = openssl_pkey_get_public($publicKey);

            if ($publicKey && openssl_verify($newHmac, $originalSignature, $publicKey, OPENSSL_ALGO_DSS1) === 1) {
                // Blockchain and reward system
                $expAward = 1000;

                // Update user exp
                $conn->query("UPDATE members SET exp = exp + $expAward WHERE username = '$fileUsername'");

                // Get updated exp
                $userExpResult = $conn->query("SELECT exp FROM members WHERE username = '$fileUsername'");
                $userExp = $userExpResult->fetch_assoc()['exp'];

                echo "File verification successful! $fileUsername has earned 1000 EXP. Total EXP: $userExp";
            } else {
                echo "File verification failed. The file and signature do not match.";
            }
        } else {
            echo "Invalid signature file format.";
        }
    }

    // Delete the uploaded file after processing
    unlink($file['tmp_name']);
}

$conn->close();

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CA Blockchain Digital Signage</title>
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f9;
            color: #333;
        }
        .hero {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            padding: 80px 20px;
            color: white;
            text-align: center;
            box-shadow: 0px 0px 15px rgba(0,0,0,0.2);
        }
        .hero h1 {
            font-size: 56px;
            margin: 0;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .hero p {
            font-size: 22px;
            margin-top: 10px;
            font-weight: 300;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        .container {
            width: 90%;
            max-width: 1200px;
            margin: 40px auto;
            text-align: center;
        }
        .form-section {
            background-color: #fff;
            padding: 40px;
            margin-bottom: 40px;
            border-radius: 10px;
            box-shadow: 0px 0px 10px rgba(0,0,0,0.1);
        }
        input[type="text"], input[type="file"] {
            width: 80%;
            padding: 15px;
            margin: 15px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 16px;
        }
        input[type="submit"] {
            padding: 15px 30px;
            background-color: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        input[type="submit"]:hover {
            background-color: #2c3e50;
        }
        #status {
            margin-top: 20px;
        }
        .modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: #fff;
            padding: 20px;
            border: 4px solid #2c3e50;
            z-index: 1000;
            box-shadow: 0px 0px 15px rgba(0, 0, 0, 0.5);
            width: 60%;
            border-radius: 10px;
        }
        .modal.active {
            display: block;
        }
        #overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
    </style>
</head>
<body>

<div class="hero">
    <h1>Blockchain Digital Signage</h1>
    <p>Verify files with blockchain technology and earn rewards</p>
    <p><strong>User:</strong> <?php echo $sessionUsername ? $sessionUsername : 'Not signed in'; ?></p>
</div>

<div class="container">
    <!-- Signup Section -->
    <div class="form-section">
       
 


 <h2>Sign Up</h2>
        <form method="post">
            <input type="text" id="signup_username" name="signup_username" placeholder="Choose a username" required>
            <input type="submit" value="Sign Up">
        </form>
    </div>

    <!-- File Upload Section -->
    <div class="form-section">
        <h2>Upload a File for Blockchain Verification</h2>
        <form enctype="multipart/form-data" method="post">
            <input type="file" id="file" name="file" required>
            <input type="text" id="file_username" name="file_username" placeholder="Enter username to reward" required>
            <label for="signature_file">Upload Signature File (for verification):</label>
            <input type="file" id="signature_file" name="signature_file">
            <input type="submit" value="Upload and Verify">
        </form>
    </div>
</div>

<div id="status"></div>

<!-- Modal for certificate -->
<div id="overlay"></div>
<div class="modal" id="certificateModal">
    <h2>Certificate of Authenticity</h2>
    <div id="certificateContent"></div>
    <p class="exp-reward">You earned 1000 EXP for verifying this file!</p>
    <button onclick="closeModal()">Close</button>
</div>

<script>
function closeModal() {
    document.getElementById('overlay').classList.remove('active');
    document.getElementById('certificateModal').classList.remove('active');
}
</script>

</body>
</html>
