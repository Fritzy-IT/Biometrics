<?php
// 1. DATABASE & SMS SETUP
include 'login_db';
include 'sms_function.php'; // The helper file we made earlier

// Set correct timezone
date_default_timezone_set('Asia/Manila');

if (isset($_POST['text'])) {
    $student_id = $_POST['text']; // Value from QR Scanner
    $current_date = date('Y-m-d');
    $current_time = date('h:i A');

    // 2. FIND STUDENT & PHONE NUMBER
    $sql = "SELECT * FROM students WHERE student_id = '$student_id'";
    $query = $conn->query($sql);

    if ($query->num_rows > 0) {
        $row = $query->fetch_assoc();
        $student_name = $row['firstname'];
        // Ensure this matches your column name for parents' number
        $phone_number = $row['contact_number']; 

        // 3. CHECK ATTENDANCE STATUS FOR TODAY
        // We check if a row exists for THIS student on THIS date
        $sql_check = "SELECT * FROM attendance WHERE student_id = '$student_id' AND date = '$current_date'";
        $query_check = $conn->query($sql_check);

        if ($query_check->num_rows > 0) {
            // --- TIME OUT LOGIC ---
            // A record exists, so this scan must be for Time Out
            $att_row = $query_check->fetch_assoc();
            
            // First, check if they have ALREADY timed out to prevent double scanning
            if($att_row['time_out'] != '' && $att_row['time_out'] != '00:00:00') {
                echo "<div class='alert alert-warning'>Student has already timed out for today!</div>";
            } else {
                // Update the existing row with Time Out
                $sql_update = "UPDATE attendance SET time_out = '$current_time' WHERE student_id = '$student_id' AND date = '$current_date'";
                
                if ($conn->query($sql_update) === TRUE) {
                    $msg = "Sievers Tech Alert: $student_name has LEFT the campus at $current_time.";
                    
                    if(!empty($phone_number)) {
                        sendSMS($phone_number, $msg);
                        echo "<div class='alert alert-success'>Time OUT Recorded. SMS Sent to Parent.</div>";
                    } else {
                        echo "<div class='alert alert-success'>Time OUT Recorded. No phone number found.</div>";
                    }
                } else {
                    echo "<div class='alert alert-danger'>Error updating record: " . $conn->error . "</div>";
                }
            }

        } else {
            // --- TIME IN LOGIC ---
            // No record found for today, so create a new one (Time In)
            $sql_insert = "INSERT INTO attendance (student_id, time_in, date, status) VALUES ('$student_id', '$current_time', '$current_date', 'Present')";
            
            if ($conn->query($sql_insert) === TRUE) {
                $msg = "Sievers Tech Alert: $student_name has ENTERED the campus at $current_time.";
                
                if(!empty($phone_number)) {
                    sendSMS($phone_number, $msg);
                    echo "<div class='alert alert-success'>Time IN Recorded. SMS Sent to Parent.</div>";
                } else {
                    echo "<div class='alert alert-success'>Time IN Recorded. No phone number found.</div>";
                }
            } else {
                echo "<div class='alert alert-danger'>Error inserting record: " . $conn->error . "</div>";
            }
        }

    } else {
        echo "<div class='alert alert-danger'>Student ID not found in database!</div>";
    }
}
?>