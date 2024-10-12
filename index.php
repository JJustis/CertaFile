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

// Blockchain file
$blockchainFile = "blockchain.json";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize blockchain if it doesn't exist
if (!file_exists($blockchainFile)) {
    file_put_contents($blockchainFile, json_encode([])); // Initialize as an empty array
}

// Function to load blockchain
function loadBlockchain() {
    global $blockchainFile;
    return json_decode(file_get_contents($blockchainFile), true);
}

// Function to add block to blockchain
function addBlockToBlockchain($block) {
    global $blockchainFile;
    $blockchain = loadBlockchain();
    $blockchain[] = $block; // Add new block
    file_put_contents($blockchainFile, json_encode($blockchain, JSON_PRETTY_PRINT)); // Save blockchain
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

            // Add block to blockchain
            $block = [
                'file' => $fileName,
                'hash' => $fileHash,
                'timestamp' => date('Y-m-d H:i:s'),
                'signed_by' => $fileUsername,
                'type' => 'File Signed'
            ];
            addBlockToBlockchain($block);

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

                // Add the file to the blockchain after successful verification
                $block = [
                    'file' => $fileName,
                    'hash' => $newHmac,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'verified_by' => $fileUsername,
                    'type' => 'File Verified'
                ];
                addBlockToBlockchain($block);

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
        /* Modal Styling */
        .modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0,0,0,0.5);
            z-index: 1000;
            width: 70%;
            padding: 30px;
            max-height: 80%;
            overflow-y: auto;
        }
        .modal h1 {
            font-size: 32px;
            margin-bottom: 20px;
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
        .close-btn {
            display: block;
            margin: 20px auto;
            background-color: #e74c3c;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .close-btn:hover {
            background-color: #c0392b;
        }
        /* Blockchain Panel Styling */
        .blockchain-section {
            margin-top: 40px;
        }
        .blockchain-panel {
            background-color: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .blockchain-panel pre {
            background-color: #f4f4f9;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
            overflow-x: auto;
            max-height: 300px;
            white-space: pre-wrap;
            font-size: 16px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>

<!-- Button to open the tutorial modal -->
<button id="openModalBtn">How to Use the Site</button>

<!-- Modal for Tutorial -->
<div id="overlay"></div>
<div id="tutorialModal" class="modal">
    <h1>How to Use Blockchain Digital Signage</h1>

    <div class="section">
        <h2>Step 1: Sign Up to Create an Account</h2>
        <p>Before you can upload or verify files, you need to sign up for an account.</p>
        <div class="steps">
            <h3>Steps:</h3>
            <ul>
                <li><strong>Step 1:</strong> Go to the homepage and find the "Sign Up" section.</li>
                <li><strong>Step 2:</strong> Enter a unique username in the "Choose a username" field.</li>
                <li><strong>Step 3:</strong> Click the "Sign Up" button. You will receive confirmation of your account creation.</li>
            </ul>
        </div>
        <p><strong>Result:</strong> Your username will be displayed at the top of the page, and a session will be started. You can now upload and verify files.</p>
    </div>

    <div class="section">
        <h2>Step 2: Upload a File for Signing (First-Time Upload)</h2>
        <p>Once you're signed in, you can upload a file to be signed by the system and generate a signature for that file.</p>
        <div class="steps">
            <h3>Steps:</h3>
            <ul>
                <li><strong>Step 1:</strong> In the "Upload a File for Blockchain Verification" section, click "Choose File" to select a file.</li>
                <li><strong>Step 2:</strong> Enter the username to reward in the "Enter username to reward" field.</li>
                <li><strong>Step 3:</strong> Click the "Upload and Verify" button.</li>
            </ul>
        </div>
        <p><strong>Result:</strong> The system will generate a `.signature` file for your uploaded file, which will automatically download. Store this signature file with your original file.</p>
        <div class="tip">
            <strong>Tip:</strong> Always keep the `.signature` file safe. It is required for future file verification.
        </div>
    </div>

    <div class="section">
        <h2>Step 3: Verify a File (Second-Time Upload with Signature)</h2>
        <p>To verify a previously signed file, follow these steps:</p>
        <div class="steps">
            <h3>Steps:</h3>
            <ul>
                <li><strong>Step 1:</strong> In the "Upload a File for Blockchain Verification" section, click "Choose File" and select the original file you want to verify.</li>
                <li><strong>Step 2:</strong> Enter the username to reward in the "Enter username to reward" field.</li>
                <li><strong>Step 3:</strong> Click the "Choose File" button next to "Upload Signature File" to select the `.signature` file you downloaded earlier.</li>
                <li><strong>Step 4:</strong> Click "Upload and Verify".</li>
            </ul>
        </div>
        <p><strong>Result:</strong> If the file matches the signature, you will receive confirmation, and the specified user will earn 1000 EXP.</p>
        <div class="tip">
            <strong>Tip:</strong> You can verify files for other users by entering their username in the "Enter username to reward" field. This allows you to help others while they gain EXP.
        </div>
    </div>

    <div class="section">
        <h2>Step 4: Track Your EXP</h2>
        <p>Each successful file verification rewards you with 1000 EXP, which can be tracked through your user account. Your total EXP is stored in the database and displayed in the leaderboard.</p>
        <div class="tip">
            <strong>Tip:</strong> You can verify files for yourself or others. Each successful verification adds 1000 EXP to the rewarded username's account.
        </div>
    </div>

    <div class="section">
        <h2>Best Practices</h2>
        <p>Here are some best practices to ensure you get the most out of the Blockchain Digital Signage system:</p>
        <ul>
            <li><strong>Keep Your Signature File Safe:</strong> Always store the `.signature` file with the original file. This ensures that you can verify it in the future.</li>
            <li><strong>Use a Unique Username:</strong> This is how the system tracks rewards. Always make sure your username is correctly entered to ensure you receive the correct amount of EXP.</li>
            <li><strong>Help Others:</strong> You can enter another user’s username in the reward field when verifying files. This is a great way to help others gain EXP.</li>
        </ul>
    </div>

    <!-- Close button -->
    <button class="close-btn" id="closeModalBtn">Close</button>
</div>

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

<!-- Blockchain Panel Section -->
<div class="blockchain-section">
    <h2>Current Blockchain</h2>
    <div class="blockchain-panel">
        <h3>Blockchain Data:</h3>
        <pre id="blockchainDisplay">Loading blockchain data...</pre>
    </div>
</div>

<script>
    // Open modal
    document.getElementById('openModalBtn').addEventListener('click', function() {
        document.getElementById('overlay').style.display = 'block';
        document.getElementById('tutorialModal').style.display = 'block';
    });

    // Close modal
    document.getElementById('closeModalBtn').addEventListener('click', function() {
        document.getElementById('overlay').style.display = 'none';
        document.getElementById('tutorialModal').style.display = 'none';
    });

    // Load blockchain data from a file using AJAX
    function loadBlockchainData() {
        fetch('blockchain.json')  // Replace with your blockchain file path
        .then(response => response.json())
        .then(data => {
            document.getElementById('blockchainDisplay').innerText = JSON.stringify(data, null, 2);
        })
        .catch(error => {
            document.getElementById('blockchainDisplay').innerText = 'Error loading blockchain data';
        });
    }

    // Call function to load blockchain data when the page loads
    window.onload = loadBlockchainData;
</script>

</body>
</html>
