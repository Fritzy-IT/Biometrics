<?php
// =============================================================
//  BACKEND LOGIC
// =============================================================

// 1. START BUFFERING & SESSION
ob_start();
session_start();
date_default_timezone_set('Asia/Manila');

// 2. DATABASE CONNECTION
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'login_db');

// Error Reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

include 'sms_function.php'; 

try {
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) { throw new Exception($conn->connect_error); }
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+08:00'");
} catch (Exception $e) {
    die("<div style='color:white; background:red; padding:20px; text-align:center;'>Database Error: " . $e->getMessage() . "</div>");
}

// --- DEPED RSS FEED & SUSPENSION CHECKER ---
function get_deped_data() {
    $cache_file = 'deped_cache.json';
    $cache_time = 3600; // 1 hour cache

    // Load from cache if fresh
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_time)) {
        return json_decode(file_get_contents($cache_file), true);
    }

    $rss_url = "https://www.deped.gov.ph/feed/";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $rss_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    // Anti-Block Headers (Para hindi harangin ng DepEd Firewall)
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36"); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

    $xml_string = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $news_data = [];
    $suspension_alert = false;
    $suspension_details = "";

    // Keywords to watch out for (Case-insensitive check later)
    $keywords = ['suspension', 'suspended', 'walang pasok', 'no classes', 'typhoon', 'storm', 'cancel', 'signal no'];

    if ($http_code == 200 && $xml_string) {
        $xml = simplexml_load_string($xml_string);
        if($xml) {
            foreach ($xml->channel->item as $item) {
                $title = (string)$item->title;
                $dateObj = strtotime((string)$item->pubDate);
                
                // SUSPENSION LOGIC:
                // Check if title contains keywords AND is recent (within 24 hours)
                foreach($keywords as $word) {
                    if (stripos($title, $word) !== false) {
                        if (time() - $dateObj < 86400) { // 86400 seconds = 24 hours
                            $suspension_alert = true;
                            $suspension_details = $title;
                        }
                    }
                }

                $news_data[] = [
                    'title' => $title,
                    'link'  => (string)$item->link,
                    'desc'  => strip_tags((string)$item->description),
                    'date'  => date('F j, Y', $dateObj)
                ];
            }
        }
    }
    
    $final_data = [
        'news' => $news_data,
        'has_suspension' => $suspension_alert,
        'suspension_msg' => $suspension_details
    ];

    file_put_contents($cache_file, json_encode($final_data));
    return $final_data;
}

// Get Data for UI
$deped_data = get_deped_data();
$deped_news = isset($deped_data['news']) ? $deped_data['news'] : [];
$has_suspension = isset($deped_data['has_suspension']) ? $deped_data['has_suspension'] : false;
$suspension_msg = isset($deped_data['suspension_msg']) ? $deped_data['suspension_msg'] : '';


// 3. DATA FETCHING (Students/Teachers)
function get_all_students($conn) {
    $data = []; $r = $conn->query("SELECT * FROM students ORDER BY student_id DESC");
    if($r) while ($row = $r->fetch_assoc()) { $data[] = $row; } return $data;
}
function get_all_teachers($conn) {
    $data = []; $r = $conn->query("SELECT * FROM teachers ORDER BY teacher_id DESC");
    if($r) while ($row = $r->fetch_assoc()) { $data[] = $row; } return $data;
}
function get_attendance_history($conn) {
    $logs = [];
    $sql = "(SELECT a.time_in, a.time_out, s.name, s.lrn as id_code, s.photo_path, 'Student' as role FROM attendance a JOIN students s ON a.student_id = s.student_id)
            UNION (SELECT a.time_in, a.time_out, t.name, t.employee_id as id_code, t.photo_path, 'Teacher' as role FROM attendance_teachers a JOIN teachers t ON a.teacher_id = t.teacher_id)
            ORDER BY time_in DESC LIMIT 150";
    $r = $conn->query($sql); if($r) while ($row = $r->fetch_assoc()) { $logs[] = $row; } return $logs;
}

// COUNTS
$count_students = 0; $count_teachers = 0;
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    $c_stu = $conn->query("SELECT COUNT(*) FROM students"); if($c_stu) $count_students = $c_stu->fetch_row()[0];
    $c_tch = $conn->query("SELECT COUNT(*) FROM teachers"); if($c_tch) $count_teachers = $c_tch->fetch_row()[0];
}

// 4. ROUTING
$page = isset($_GET['page']) ? $_GET['page'] : 'login';
$message_type = ''; $message_content = '';
if (isset($_SESSION['flash_message'])) {
    $message_type = $_SESSION['flash_message']['type'];
    $message_content = $_SESSION['flash_message']['content'];
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    $page = 'dashboard';
    $username = htmlspecialchars($_SESSION["username"]);
}

// 5. POST REQUESTS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'log_attendance') {
        ob_clean(); header('Content-Type: application/json');
        $code = trim($_POST['qr_data']);
        
        $stmt = $conn->prepare("SELECT student_id, name, photo_path, contact_number FROM students WHERE lrn = ?");
        $stmt->bind_param("s", $code); $stmt->execute(); $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $r = $res->fetch_assoc();
            $id=$r['student_id']; $name=$r['name']; $photo=$r['photo_path']; $ph=$r['contact_number'];
            $table="attendance"; $col="student_id"; $role="Student";
        } else {
            $stmt = $conn->prepare("SELECT teacher_id, name, photo_path, contact_number FROM teachers WHERE employee_id = ?");
            $stmt->bind_param("s", $code); $stmt->execute(); $res = $stmt->get_result();
            if ($res->num_rows > 0) {
                $r = $res->fetch_assoc();
                $id=$r['teacher_id']; $name=$r['name']; $photo=$r['photo_path']; $ph=$r['contact_number'];
                $table="attendance_teachers"; $col="teacher_id"; $role="Teacher";
            } else {
                echo json_encode(['status'=>'error', 'message'=>'ID Not Found']); exit;
            }
        }

        $check = $conn->query("SELECT attendance_id, time_in FROM $table WHERE $col = $id AND DATE(time_in) = CURDATE() AND time_out IS NULL");
        if ($check->num_rows > 0) {
            $sess = $check->fetch_assoc();
            $aid = $sess['attendance_id'];
            $diff = (time() - strtotime($sess['time_in'])) / 60;
            if ($diff < 1) { echo json_encode(['status'=>'error', 'message'=>'Wait 1 min before checking out.']); } 
            else {
                $conn->query("UPDATE $table SET time_out = NOW() WHERE attendance_id = $aid");
                if(!empty($ph) && function_exists('sendSMS')) { sendSMS($ph, "ALERT: $name ($role) has DEPARTED school at " . date('h:i A')); }
                echo json_encode(['status'=>'success', 'type'=>'OUT', 'name'=>$name, 'role'=>$role, 'photo'=>$photo, 'time'=>date('h:i A')]);
            }
        } else {
            $done = $conn->query("SELECT attendance_id FROM $table WHERE $col = $id AND DATE(time_in) = CURDATE() AND time_out IS NOT NULL");
            if ($done->num_rows > 0) { echo json_encode(['status'=>'error', 'message'=>'Attendance completed today.']); } 
            else {
                $spam = $conn->query("SELECT attendance_id FROM $table WHERE $col = $id AND time_out > (NOW() - INTERVAL 1 MINUTE)");
                if ($spam->num_rows > 0) { echo json_encode(['status'=>'error', 'message'=>'Please wait before scanning again.']); } 
                else {
                    $conn->query("INSERT INTO $table ($col, time_in) VALUES ($id, NOW())");
                    if(!empty($ph) && function_exists('sendSMS')) { sendSMS($ph, "ALERT: $name ($role) has ARRIVED at school at " . date('h:i A')); }
                    echo json_encode(['status'=>'success', 'type'=>'IN', 'name'=>$name, 'role'=>$role, 'photo'=>$photo, 'time'=>date('h:i A')]);
                }
            }
        }
        exit;
    }
    elseif ($action === 'post_announcement') {
        set_time_limit(0); $msg = trim($_POST['message']);
        if(empty($msg)) { $_SESSION['flash_message'] = ['type'=>'error', 'content'=>'Message cannot be empty.']; header("Location: index.php"); exit; }
        try { $stmt = $conn->prepare("INSERT INTO announcements (message) VALUES (?)"); $stmt->bind_param("s", $msg); $stmt->execute(); } catch (Exception $e) {}
        $recipients = [];
        $res_s = $conn->query("SELECT contact_number FROM students WHERE contact_number IS NOT NULL AND contact_number != ''");
        while ($row = $res_s->fetch_assoc()) { $recipients[] = $row['contact_number']; }
        $res_t = $conn->query("SELECT contact_number FROM teachers WHERE contact_number IS NOT NULL AND contact_number != ''");
        while ($row = $res_t->fetch_assoc()) { $recipients[] = $row['contact_number']; }
        $sent_count = 0; $sms_message = "ANNOUNCEMENT: " . $msg;
        foreach ($recipients as $number) { if(function_exists('sendSMS')) { sendSMS($number, $sms_message); $sent_count++; sleep(1); } }
        $_SESSION['flash_message'] = ['type'=>'success', 'content'=>"Broadcast sent to $sent_count people!"]; header("Location: index.php"); exit;
    }
    elseif ($action === 'login') {
        $u = trim($_POST['username']); $stmt = $conn->prepare("SELECT user_id, username, password_hash FROM users WHERE username = ?");
        $stmt->bind_param("s", $u); $stmt->execute(); $res = $stmt->get_result();
        if ($res->num_rows === 1) {
            $row = $res->fetch_assoc();
            if (password_verify($_POST['password'], $row['password_hash'])) { $_SESSION["loggedin"] = true; $_SESSION["username"] = $row['username']; header("Location: index.php"); exit; }
        }
        $message_type = 'error'; $message_content = 'Invalid Credentials.';
    }
    elseif ($action === 'register_student') { $photo=null; if(!empty($_FILES['student_picture']['name'])){$target='uploads/'.$_POST['lrn'].'_'.time().'.jpg';if(!is_dir('uploads'))mkdir('uploads');if(move_uploaded_file($_FILES['student_picture']['tmp_name'],$target))$photo=$target;} try{$stmt=$conn->prepare("INSERT INTO students (lrn, name, gender, email, contact_number, address, photo_path) VALUES (?,?,?,?,?,?,?)");$stmt->bind_param("sssssss",$_POST['lrn'],$_POST['name'],$_POST['gender'],$_POST['email'],$_POST['contact'],$_POST['address'],$photo);$stmt->execute();$_SESSION['flash_message']=['type'=>'success','content'=>'Student Registered'];}catch(Exception $e){$_SESSION['flash_message']=['type'=>'error','content'=>'Error: LRN may exist.'];} header("Location: index.php"); exit;}
    elseif ($action === 'register_teacher') { $photo=null; $eid="T-".time(); if(!empty($_FILES['teacher_picture']['name'])){$target='uploads/'.$eid.'.jpg';if(!is_dir('uploads'))mkdir('uploads');if(move_uploaded_file($_FILES['teacher_picture']['tmp_name'],$target))$photo=$target;} try{$stmt=$conn->prepare("INSERT INTO teachers (employee_id, name, gender, email, contact_number, address, photo_path) VALUES (?,?,?,?,?,?,?)");$stmt->bind_param("sssssss",$eid,$_POST['name'],$_POST['gender'],$_POST['email'],$_POST['contact'],$_POST['address'],$photo);$stmt->execute();$_SESSION['flash_message']=['type'=>'success','content'=>'Teacher Registered'];}catch(Exception $e){$_SESSION['flash_message']=['type'=>'error','content'=>'Error Saving Teacher.'];} header("Location: index.php"); exit;}
    elseif ($action === 'update_student') { $id=$_POST['student_id']; $photo=$_POST['current_photo']; if(!empty($_FILES['student_picture']['name'])){$target='uploads/'.$_POST['lrn'].'_upd_'.time().'.jpg';if(move_uploaded_file($_FILES['student_picture']['tmp_name'],$target))$photo=$target;} $sql="UPDATE students SET lrn=?, name=?, gender=?, email=?, contact_number=?, address=?, photo_path=? WHERE student_id=?"; $stmt=$conn->prepare($sql); $stmt->bind_param("sssssssi",$_POST['lrn'],$_POST['name'],$_POST['gender'],$_POST['email'],$_POST['contact'],$_POST['address'],$photo,$id); $stmt->execute(); $_SESSION['flash_message']=['type'=>'success','content'=>'Student Updated']; header("Location: index.php"); exit;}
    elseif ($action === 'update_teacher') { $id=$_POST['teacher_id']; $photo=$_POST['current_photo']; if(!empty($_FILES['teacher_picture']['name'])){$target='uploads/T_upd_'.time().'.jpg';if(move_uploaded_file($_FILES['teacher_picture']['tmp_name'],$target))$photo=$target;} $sql="UPDATE teachers SET name=?, gender=?, email=?, contact_number=?, address=?, photo_path=? WHERE teacher_id=?"; $stmt=$conn->prepare($sql); $stmt->bind_param("ssssssi",$_POST['name'],$_POST['gender'],$_POST['email'],$_POST['contact'],$_POST['address'],$photo,$id); $stmt->execute(); $_SESSION['flash_message']=['type'=>'success','content'=>'Teacher Updated']; header("Location: index.php"); exit;}
    elseif ($action === 'delete_student') { $conn->query("DELETE FROM students WHERE student_id=".intval($_POST['student_id'])); $_SESSION['flash_message']=['type'=>'success','content'=>'Student Deleted']; header("Location: index.php"); exit;}
    elseif ($action === 'delete_teacher') { $conn->query("DELETE FROM teachers WHERE teacher_id=".intval($_POST['teacher_id'])); $_SESSION['flash_message']=['type'=>'success','content'=>'Teacher Deleted']; header("Location: index.php"); exit;}
    elseif ($action === 'signup') { $u=trim($_POST['username']); $p=password_hash($_POST['password'],PASSWORD_DEFAULT); $e=trim($_POST['email']); try{$stmt=$conn->prepare("INSERT INTO users (username, email, password_hash) VALUES (?,?,?)");$stmt->bind_param("sss",$u,$e,$p);$stmt->execute();$_SESSION['flash_message']=['type'=>'success','content'=>'Admin Created!'];header("Location: index.php"); exit;}catch(Exception $e){$message_type='error';$message_content='Username taken.';$page='signup';}}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sievers Tech System</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            /* Neon Glass Palette */
            --bg-deep: #050511;
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --glass-highlight: rgba(255, 255, 255, 0.15);
            --neon-green: #00ff9d;
            --neon-blue: #00d2ff;
            --neon-orange: #ff9e00;
            --neon-red: #ff3b3b;
            --text-main: #ffffff;
            --text-muted: #8b9bb4;
            --shadow-glass: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }

        * { box-sizing: border-box; outline: none; }
        
        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-deep);
            background-image: 
                radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), 
                radial-gradient(at 50% 0%, hsla(225,39%,30%,1) 0, transparent 50%), 
                radial-gradient(at 100% 0%, hsla(339,49%,30%,1) 0, transparent 50%);
            background-attachment: fixed;
            background-size: cover;
            color: var(--text-main);
            margin: 0;
            min-height: 100vh;
            overflow: hidden; 
        }

        .blob { position: fixed; filter: blur(80px); z-index: -1; opacity: 0.4; animation: move 10s infinite alternate; }
        .blob-1 { top: 10%; left: 10%; width: 300px; height: 300px; background: var(--neon-blue); }
        .blob-2 { bottom: 10%; right: 10%; width: 400px; height: 400px; background: #7000ff; animation-duration: 15s; }
        @keyframes move { from { transform: translate(0,0) rotate(0deg); } to { transform: translate(50px, 50px) rotate(20deg); } }

        .glass-panel { background: var(--glass-bg); backdrop-filter: blur(12px); border: 1px solid var(--glass-border); box-shadow: var(--shadow-glass); border-radius: 24px; overflow: hidden; transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .login-wrapper { display: flex; justify-content: center; align-items: center; height: 100vh; position: relative; z-index: 10; }
        .login-box { width: 100%; max-width: 380px; padding: 50px 40px; border-top: 1px solid var(--glass-highlight); text-align: center; }
        .login-title { font-size: 2rem; font-weight: 700; margin-bottom: 30px; background: linear-gradient(to right, #fff, #aaa); -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: -1px; }

        .input-group { margin-bottom: 20px; text-align: left; }
        .input-group label { display: block; margin-bottom: 8px; color: var(--text-muted); font-size: 0.85rem; font-weight: 500; text-transform: uppercase; letter-spacing: 1px; }
        .input-group input, .input-group select, .input-group textarea { width: 100%; padding: 14px 16px; background: rgba(0, 0, 0, 0.2); border: 1px solid var(--glass-border); border-radius: 12px; color: #fff; font-family: inherit; font-size: 1rem; transition: 0.3s; }
        .input-group input:focus { border-color: var(--neon-blue); box-shadow: 0 0 15px rgba(0, 210, 255, 0.2); background: rgba(0, 0, 0, 0.4); }

        .btn { width: 100%; padding: 14px; border: none; border-radius: 12px; background: linear-gradient(135deg, var(--neon-green), #00b36e); color: #000; font-weight: 700; font-size: 1rem; cursor: pointer; transition: 0.3s; box-shadow: 0 4px 15px rgba(0, 255, 157, 0.3); text-transform: uppercase; letter-spacing: 0.5px; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0, 255, 157, 0.5); }
        .btn-red { background: linear-gradient(135deg, #ff5e5e, #d63031); color: white; box-shadow: 0 4px 15px rgba(255, 94, 94, 0.3); }

        .header { background: rgba(20, 20, 30, 0.6); backdrop-filter: blur(20px); padding: 15px 40px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--glass-border); position: sticky; top: 0; z-index: 100; height: 70px; }
        .status-badge { font-size: 0.75rem; padding: 6px 12px; border-radius: 30px; background: rgba(0,0,0,0.3); border: 1px solid var(--glass-border); display: inline-flex; align-items: center; gap: 6px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; box-shadow: 0 0 8px currentColor; }
        .online { color: var(--neon-green); } .offline { color: var(--neon-red); }

        .dashboard-container { display: flex; height: calc(100vh - 70px); padding: 30px 40px 50px 40px; gap: 30px; max-width: 1600px; margin: 0 auto; }
        .dashboard-left { flex: 3; display: flex; flex-direction: column; gap: 20px; height: 100%; overflow: hidden; }
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 20px; align-content: start; overflow-y: auto; flex-grow: 1; padding-right: 5px; }

        .weather-widget { flex-shrink: 0; height: 110px; background: linear-gradient(145deg, rgba(255,255,255,0.05), rgba(255,255,255,0.01)); border: 1px solid var(--glass-border); backdrop-filter: blur(12px); border-radius: 20px; display: flex; align-items: center; justify-content: space-between; padding: 0 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); color: #fff; transition: transform 0.3s; }
        .weather-widget:hover { border-color: rgba(255,255,255,0.3); transform: translateY(-2px); }
        .ww-left { display: flex; align-items: center; gap: 15px; }
        .ww-icon { width: 50px; height: 50px; filter: drop-shadow(0 0 8px rgba(0,210,255,0.6)); }
        .ww-temp { font-size: 2.2rem; font-weight: 700; margin: 0; background: linear-gradient(to bottom, #fff, #bbb); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .ww-desc { font-size: 0.85rem; color: var(--neon-blue); text-transform: uppercase; letter-spacing: 1px; margin: 0; }
        .ww-right { text-align: right; }
        .ww-city { font-size: 1.1rem; font-weight: 600; margin: 0; color: var(--text-main); }
        .ww-date { font-size: 0.8rem; color: var(--text-muted); margin-top: 5px; }

        .dashboard-right { flex: 1.2; display: flex; flex-direction: column; }
        .chart-card { background: linear-gradient(145deg, rgba(255,255,255,0.05), rgba(255,255,255,0.01)); border: 1px solid var(--glass-border); border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); padding: 25px; text-align: center; height: 100%; display: flex; flex-direction: column; justify-content: center; }
        .chart-card h3 { margin: 0 0 15px; font-size: 1rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; }

        .card { background: linear-gradient(145deg, rgba(255,255,255,0.05), rgba(255,255,255,0.01)); padding: 25px 15px; border-radius: 20px; text-align: center; cursor: pointer; border: 1px solid var(--glass-border); transition: all 0.3s; position: relative; overflow: hidden; height: 140px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .card:hover { transform: translateY(-5px); border-color: rgba(255,255,255,0.3); box-shadow: 0 10px 30px rgba(0,0,0,0.4); }
        .card i { font-size: 2.5rem; margin-bottom: 15px; background: linear-gradient(to bottom right, #fff, #888); -webkit-background-clip: text; -webkit-text-fill-color: transparent; filter: drop-shadow(0 0 10px rgba(255,255,255,0.2)); }
        .card span { font-weight: 600; font-size: 0.9rem; color: var(--text-muted); }
        .card:hover span { color: #fff; }

        .modal { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(5, 5, 17, 0.8); backdrop-filter: blur(8px); align-items: center; justify-content: center; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-content { background: rgba(20, 20, 30, 0.85); border: 1px solid rgba(255, 255, 255, 0.15); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); padding: 40px; border-radius: 24px; width: 90%; max-width: 600px; position: relative; max-height: 90vh; overflow-y: auto; animation: slideUp 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        @keyframes slideUp { from { transform: translateY(50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-content.large { max-width: 1100px; }
        .close { position: absolute; right: 25px; top: 25px; font-size: 24px; cursor: pointer; color: var(--text-muted); transition: 0.2s; }
        .close:hover { color: var(--neon-red); transform: rotate(90deg); }
        .modal h3 { margin-top: 0; font-size: 1.5rem; background: linear-gradient(to right, #fff, var(--neon-blue)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

        .table-wrapper { overflow-x: auto; border-radius: 16px; border: 1px solid var(--glass-border); margin-top: 20px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.95em; }
        th { background: rgba(255, 255, 255, 0.05); color: var(--neon-green); padding: 18px; text-align: left; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; font-weight: 700; }
        td { padding: 16px; border-bottom: 1px solid var(--glass-border); color: #ddd; vertical-align: middle; }
        tr:hover td { background: rgba(255, 255, 255, 0.03); color: #fff; }
        .photo-thumb { width: 45px; height: 45px; border-radius: 12px; object-fit: cover; }

        .qr-main-container { display: flex; gap: 30px; height: 450px; margin-top: 20px; }
        .qr-left-pane { flex: 1; border-right: 1px solid var(--glass-border); padding-right: 20px; display: flex; flex-direction: column; }
        .qr-right-pane { width: 320px; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(0,0,0,0.15); border-radius: 20px; border: 1px solid var(--glass-border); }
        .qr-list-container { overflow-y: auto; flex: 1; border: 1px solid var(--glass-border); border-radius: 12px; margin-top: 10px; background: rgba(0,0,0,0.2); }
        .list-item { padding: 15px; border-bottom: 1px solid var(--glass-border); cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: 0.2s; }
        .list-item:hover { background: var(--glass-highlight); padding-left: 20px; border-left: 3px solid var(--neon-green); }
        .list-item strong { color: #fff; font-weight: 500; }
        .hidden { display: none !important; }

        .tabs { background: rgba(0,0,0,0.3); border-radius: 50px; padding: 5px; display: inline-flex; border: 1px solid var(--glass-border); margin-bottom: 20px; }
        .tab-btn { background: transparent; border: none; color: var(--text-muted); padding: 10px 30px; border-radius: 40px; cursor: pointer; font-weight: 600; transition: 0.3s; }
        .tab-btn.active { background: var(--glass-highlight); color: #fff; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        
        .msg { padding: 15px; border-radius: 12px; text-align: center; margin: 20px auto; max-width: 600px; backdrop-filter: blur(10px); transition: opacity 1s ease-out, transform 1s ease-out; opacity: 1; }
        .msg.success { background: rgba(0, 255, 157, 0.15); border: 1px solid var(--neon-green); color: var(--neon-green); }
        .msg.error { background: rgba(255, 59, 59, 0.15); border: 1px solid var(--neon-red); color: var(--neon-red); }
        .msg.msg-hide { opacity: 0; transform: translateY(-20px); pointer-events: none; }

        /* --- NEWS LIST STYLES --- */
        .news-list { display: flex; flex-direction: column; gap: 15px; }
        .news-item { background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); padding: 20px; border-radius: 15px; text-decoration: none; color: #fff; transition: 0.2s; display: flex; flex-direction: column; }
        .news-item:hover { background: rgba(255,255,255,0.08); border-color: var(--neon-blue); transform: translateX(5px); }
        .news-date { font-size: 0.8rem; color: var(--neon-orange); font-weight: bold; margin-bottom: 5px; }
        .news-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 5px; color: #fff; }
        .news-desc { font-size: 0.9rem; color: var(--text-muted); line-height: 1.5; }

        /* --- SUSPENSION BOX STYLES --- */
        .suspension-box {
            text-align: center; padding: 40px; border-radius: 20px; color: #fff;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            border: 2px solid rgba(255,255,255,0.1);
        }
        .suspension-green { background: linear-gradient(135deg, #27ae60, #2ecc71); box-shadow: 0 10px 40px rgba(39, 174, 96, 0.4); }
        .suspension-red { background: linear-gradient(135deg, #c0392b, #e74c3c); box-shadow: 0 10px 40px rgba(192, 57, 43, 0.4); }
        
        .sus-icon { font-size: 5rem; margin-bottom: 20px; filter: drop-shadow(0 5px 15px rgba(0,0,0,0.3)); }
        .sus-title { font-size: 2.5rem; font-weight: 800; text-transform: uppercase; margin: 0; letter-spacing: 2px; }
        .sus-desc { font-size: 1.2rem; margin-top: 10px; opacity: 0.9; }
        .sus-info { margin-top: 20px; font-size: 0.9rem; background: rgba(0,0,0,0.2); padding: 10px 20px; border-radius: 20px; }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
    </style>
</head>
<body>

<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<?php if ($page === 'login' || $page === 'signup'): ?>
    <div class="login-wrapper">
        <div class="glass-panel login-box">
            <h2 class="login-title">ATTENDANCE<br>SYSTEM</h2>
            <?php if ($message_content): ?>
                <div class="msg <?php echo $message_type; ?>">
                    <?= $message_content ?>
                </div>
            <?php endif; ?>
            <form action="index.php" method="POST">
                <input type="hidden" name="action" value="<?= $page ?>">
                <?php if($page==='signup'): ?><div class="input-group"><label>Email</label><input type="email" name="email" required placeholder="Enter valid email"></div><?php endif; ?>
                <div class="input-group"><label>Username</label><input type="text" name="username" required autocomplete="off" placeholder="Enter username"></div>
                <div class="input-group"><label>Password</label><input type="password" name="password" required autocomplete="off" placeholder="Enter password"></div>
                <button type="submit" class="btn"><?= $page==='login'?'Sign In':'Create Account' ?></button>
            </form>
            <div style="text-align:center; margin-top:25px;">
                <a href="?page=<?= $page==='login'?'signup':'login' ?>" style="color:var(--text-muted); text-decoration:none; font-size:0.9rem; transition:0.3s; border-bottom:1px dotted #666;">
                    <?= $page==='login'?'No account? Sign Up':'Already have an account?' ?>
                </a>
            </div>
            <div style="margin-top:40px; font-size:0.75rem; color:rgba(255,255,255,0.3);">POWERED BY FRITZYBOY</div>
        </div>
    </div>

<?php else: ?>

    <div class="header">
        <div style="display:flex; align-items:center; gap:20px;">
            <div>
                <h2 style="margin:0; font-size:1.5rem; letter-spacing:-0.5px;">DASHBOARD</h2>
                <small style="color:var(--text-muted); text-transform:uppercase; font-size:0.75rem; letter-spacing:1px;">Welcome, <?= $username ?></small>
            </div>
            <?php 
                $gatewayStatus = false;
                if(function_exists('checkGatewayConnection')) { $gatewayStatus = checkGatewayConnection(); }
            ?>
            <div class="status-badge">
                SMS: <?php if($gatewayStatus): ?><span class="dot online"></span> <span style="color:#fff;">Online</span><?php else: ?><span class="dot offline"></span> <span style="color:#aaa;">Offline</span><?php endif; ?>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:20px;">
            <span id="clock" style="font-family:'Outfit'; font-variant-numeric:tabular-nums; font-size:1.1rem; color:var(--neon-blue);"></span>
            <a href="logout.php" class="btn btn-red" style="padding:10px 20px; font-size:0.8rem; width:auto;">Logout</a>
        </div>
    </div>

    <?php if ($message_content): ?><div class="msg <?php echo $message_type; ?>"><?= $message_content ?></div><?php endif; ?>
        
    <div class="dashboard-container">
        
        <div class="dashboard-left">
            
            <div class="cards-grid">
                <div class="card" onclick="showModal('regStudent')">
                    <i class="fas fa-user-plus"></i>
                    <span>Register Student</span>
                </div>
                <div class="card" onclick="showModal('regTeacher')">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span>Register Teacher</span>
                </div>
                <div class="card" onclick="showModal('viewStudent')">
                    <i class="fas fa-users"></i>
                    <span>Student List</span>
                </div>
                <div class="card" onclick="showModal('viewTeacher')">
                    <i class="fas fa-id-card"></i>
                    <span>Teacher List</span>
                </div>
                <div class="card" onclick="showModal('genQR')">
                    <i class="fas fa-qrcode"></i>
                    <span>Generate QR</span>
                </div>
                <div class="card" onclick="openScanner()">
                    <i class="fas fa-camera" style="-webkit-text-fill-color: var(--neon-green);"></i>
                    <span style="color:#fff;">Scanner</span>
                </div>
                <div class="card" onclick="showModal('logs')">
                    <i class="fas fa-history"></i>
                    <span>History Logs</span>
                </div>
                <div class="card" onclick="showModal('depedModal')">
                    <i class="fas fa-newspaper" style="-webkit-text-fill-color: #ffd700;"></i>
                    <span>DepEd Updates</span>
                </div>
                <div class="card" onclick="showModal('suspensionModal')">
                    <i class="fas fa-umbrella" style="-webkit-text-fill-color: #be2edd;"></i>
                    <span>Class Suspension</span>
                </div>
                <div class="card" onclick="showModal('broadcastModal')">
                    <i class="fas fa-bullhorn" style="-webkit-text-fill-color: var(--neon-orange);"></i>
                    <span>Broadcast</span>
                </div>
            </div>

            <div class="weather-widget">
                <div class="ww-left">
                    <img id="w-icon" src="" alt="Weather" class="ww-icon" style="display:none;">
                    <div>
                        <h2 id="w-temp" class="ww-temp">--°</h2>
                        <p id="w-desc" class="ww-desc">Loading...</p>
                    </div>
                </div>
                <div class="ww-right">
                    <h3 id="w-city" class="ww-city">Philippines</h3>
                    <p id="w-date" class="ww-date">--:-- --</p>
                </div>
            </div>

        </div>

        <div class="dashboard-right">
            <div class="chart-card">
                <h3>Registered Population</h3>
                <div style="position: relative; height:250px; width:100%;">
                    <canvas id="popChart"></canvas>
                </div>
            </div>
        </div>

    </div>

    <div id="regStudent" class="modal"><div class="modal-content"><span class="close" onclick="hideModal('regStudent')">&times;</span><h3>Register Student</h3><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="register_student"><div class="input-group"><label>Name</label><input type="text" name="name" required placeholder="Full Name"></div><div class="input-group"><label>LRN</label><input type="text" name="lrn" required placeholder="Student ID"></div><div class="input-group"><label>Gender</label><select name="gender"><option>Male</option><option>Female</option></select></div><div class="input-group"><label>Email</label><input type="email" name="email"></div><div class="input-group"><label>Contact</label><input type="text" name="contact" placeholder="09xxxxxxxxx"></div><div class="input-group"><label>Address</label><input type="text" name="address"></div><div class="input-group"><label>Photo</label><input type="file" name="student_picture"></div><button class="btn" style="width:100%">Save Record</button></form></div></div>
    <div id="regTeacher" class="modal"><div class="modal-content"><span class="close" onclick="hideModal('regTeacher')">&times;</span><h3>Register Teacher</h3><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="register_teacher"><div class="input-group"><label>Name</label><input type="text" name="name" required></div><div class="input-group"><label>Gender</label><select name="gender"><option>Male</option><option>Female</option></select></div><div class="input-group"><label>Email</label><input type="email" name="email"></div><div class="input-group"><label>Contact</label><input type="text" name="contact"></div><div class="input-group"><label>Address</label><input type="text" name="address"></div><div class="input-group"><label>Photo</label><input type="file" name="teacher_picture"></div><button class="btn" style="width:100%">Save Record</button></form></div></div>
    <div id="viewStudent" class="modal"><div class="modal-content large"><span class="close" onclick="hideModal('viewStudent')">&times;</span><h3>Student Database</h3><div class="input-group"><input type="text" id="sSearch" class="search-box" onkeyup="filterTable('sSearch','sTable')" placeholder="Search student name..."></div><div class="table-wrapper" style="max-height:450px;"><table id="sTable"><thead><tr><th>Photo</th><th>LRN</th><th>Name</th><th>Contact</th><th>Actions</th></tr></thead><tbody><?php foreach(get_all_students($conn) as $s): ?><tr><td><img src="<?= $s['photo_path']?:'https://via.placeholder.com/40' ?>" class="photo-thumb"></td><td><?= $s['lrn'] ?></td><td><?= $s['name'] ?></td><td><?= $s['contact_number'] ?></td><td><button class="btn" style="width:auto; padding:5px 10px; font-size:0.8rem; background:var(--neon-orange);" onclick='openUpdStudent(<?= json_encode($s) ?>)'><i class="fas fa-edit"></i></button><form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete_student"><input type="hidden" name="student_id" value="<?= $s['student_id'] ?>"><button class="btn btn-red" style="width:auto; padding:5px 10px; font-size:0.8rem;"><i class="fas fa-trash"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div>
    <div id="viewTeacher" class="modal"><div class="modal-content large"><span class="close" onclick="hideModal('viewTeacher')">&times;</span><h3>Teacher Database</h3><div class="input-group"><input type="text" id="tSearch" class="search-box" onkeyup="filterTable('tSearch','tTable')" placeholder="Search teacher name..."></div><div class="table-wrapper" style="max-height:450px;"><table id="tTable"><thead><tr><th>Photo</th><th>ID</th><th>Name</th><th>Contact</th><th>Actions</th></tr></thead><tbody><?php foreach(get_all_teachers($conn) as $t): ?><tr><td><img src="<?= $t['photo_path']?:'https://via.placeholder.com/40' ?>" class="photo-thumb"></td><td><?= $t['employee_id'] ?></td><td><?= $t['name'] ?></td><td><?= $t['contact_number'] ?></td><td><button class="btn" style="width:auto; padding:5px 10px; font-size:0.8rem; background:var(--neon-orange);" onclick='openUpdTeacher(<?= json_encode($t) ?>)'><i class="fas fa-edit"></i></button><form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete_teacher"><input type="hidden" name="teacher_id" value="<?= $t['teacher_id'] ?>"><button class="btn btn-red" style="width:auto; padding:5px 10px; font-size:0.8rem;"><i class="fas fa-trash"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div>
    <div id="updStudent" class="modal"><div class="modal-content"><span class="close" onclick="hideModal('updStudent')">&times;</span><h3>Update Student</h3><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="update_student"><input type="hidden" name="student_id" id="us_id"><input type="hidden" name="current_photo" id="us_photo"><div class="input-group"><label>LRN</label><input type="text" name="lrn" id="us_lrn" required></div><div class="input-group"><label>Name</label><input type="text" name="name" id="us_name" required></div><div class="input-group"><label>Gender</label><select name="gender" id="us_gen"><option>Male</option><option>Female</option></select></div><div class="input-group"><label>Email</label><input type="email" name="email" id="us_email"></div><div class="input-group"><label>Contact</label><input type="text" name="contact" id="us_con"></div><div class="input-group"><label>Address</label><input type="text" name="address" id="us_add"></div><div class="input-group"><label>New Photo</label><input type="file" name="student_picture"></div><button type="submit" class="btn" style="width:100%">Update</button></form></div></div>
    <div id="updTeacher" class="modal"><div class="modal-content"><span class="close" onclick="hideModal('updTeacher')">&times;</span><h3>Update Teacher</h3><form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="update_teacher"><input type="hidden" name="teacher_id" id="ut_id"><input type="hidden" name="current_photo" id="ut_photo"><div class="input-group"><label>Name</label><input type="text" name="name" id="ut_name" required></div><div class="input-group"><label>Gender</label><select name="gender" id="ut_gen"><option>Male</option><option>Female</option></select></div><div class="input-group"><label>Email</label><input type="email" name="email" id="ut_email"></div><div class="input-group"><label>Contact</label><input type="text" name="contact" id="ut_con"></div><div class="input-group"><label>Address</label><input type="text" name="address" id="ut_add"></div><div class="input-group"><label>New Photo</label><input type="file" name="teacher_picture"></div><button type="submit" class="btn" style="width:100%">Update</button></form></div></div>

    <div id="genQR" class="modal"><div class="modal-content large"><span class="close" onclick="hideModal('genQR')">&times;</span><h3 style="background:linear-gradient(to right, #fff, var(--neon-blue));-webkit-background-clip:text;-webkit-text-fill-color:transparent;">Generate QR Code</h3><div class="qr-main-container"><div class="qr-left-pane"><div class="input-group"><select onchange="toggleQrList(this.value)"><option value="stu">Show Students</option><option value="tch">Show Teachers</option></select></div><div id="qr-stu-list" style="display:flex;flex-direction:column;height:100%;"><div class="input-group" style="margin-bottom:10px;"><input type="text" id="qrs" onkeyup="filterDivList('qrs','qrStuListDiv')" placeholder="Search Student Name..."></div><div id="qrStuListDiv" class="qr-list-container"><?php foreach(get_all_students($conn) as $s): ?><div class="list-item" onclick="makeQR('<?= $s['lrn'] ?>','<?= $s['name'] ?>')"><strong><?= $s['name'] ?></strong><span class="lrn"><?= $s['lrn'] ?></span></div><?php endforeach; ?></div></div><div id="qr-tch-list" class="hidden" style="flex-direction:column;height:100%;"><div class="input-group" style="margin-bottom:10px;"><input type="text" id="qrt" onkeyup="filterDivList('qrt','qrTchListDiv')" placeholder="Search Teacher Name..."></div><div id="qrTchListDiv" class="qr-list-container"><?php foreach(get_all_teachers($conn) as $t): ?><div class="list-item" onclick="makeQR('<?= $t['employee_id'] ?>','<?= $t['name'] ?>')"><strong><?= $t['name'] ?></strong><span class="lrn"><?= $t['employee_id'] ?></span></div><?php endforeach; ?></div></div></div><div class="qr-right-pane"><div id="qrcode" style="background:#fff;padding:15px;border-radius:12px;margin-bottom:20px;"></div><h4 id="qrName" style="color:var(--neon-green);margin:0 0 15px 0;text-align:center;">Select a person</h4><button class="btn" onclick="downloadQR()" style="width:80%;background:linear-gradient(135deg,var(--neon-blue),#007bff);">Download QR</button></div></div></div></div>
    <div id="scanModal" class="modal"><div class="modal-content"><span class="close" onclick="closeScanner()">&times;</span><h3>Live Scanner</h3><div id="reader" style="border-radius:12px;overflow:hidden;"></div><h4 id="scanResult" style="text-align:center;margin-top:20px;color:var(--text-muted);">Point camera at QR Code</h4></div></div>
    <div id="broadcastModal" class="modal"><div class="modal-content"><span class="close" onclick="hideModal('broadcastModal')">&times;</span><h3 style="background:linear-gradient(to right,#ff9e00,#ff0055);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">Broadcast SMS</h3><p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:20px;border-left:3px solid var(--neon-orange);padding-left:15px;">Sending this will notify ALL students and teachers. Please use responsibly.</p><form method="POST"><input type="hidden" name="action" value="post_announcement"><div class="input-group"><label>Announcement Message</label><textarea name="message" rows="5" required placeholder="Type your message here..."></textarea></div><button type="submit" class="btn" style="background:linear-gradient(135deg,#ff9e00,#ff5e00);"><i class="fas fa-paper-plane"></i> Send Blast</button></form></div></div>
    
    <div id="logs" class="modal">
        <div class="modal-content large">
            <span class="close" onclick="hideModal('logs')">&times;</span>
            <h3>Attendance History</h3>
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('stu')">Students</button>
                <button class="tab-btn" onclick="switchTab('tch')">Teachers</button>
            </div>
            <div id="log-stu">
                <div class="table-wrapper" style="max-height:450px;">
                    <table>
                        <thead><tr><th>Name</th><th>Role</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach(get_attendance_history($conn) as $l): if($l['role']!='Student')continue; ?>
                            <tr>
                                <td><?= $l['name'] ?></td>
                                <td><span style="color:var(--neon-blue);font-weight:700;font-size:0.75rem;text-transform:uppercase;"><?= $l['role'] ?></span></td>
                                <td><?= date('h:i A',strtotime($l['time_in'])) ?></td>
                                <td><?= $l['time_out']?date('h:i A',strtotime($l['time_out'])):'--' ?></td>
                                <td><span style="color:<?= $l['time_out']?'var(--text-muted)':'var(--neon-green)' ?>;border:1px solid currentColor;padding:3px 10px;border-radius:20px;font-size:0.7rem;"><?= $l['time_out']?'COMPLETED':'ACTIVE' ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div id="log-tch" class="hidden">
                <div class="table-wrapper" style="max-height:450px;">
                    <table>
                        <thead><tr><th>Name</th><th>Role</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach(get_attendance_history($conn) as $l): if($l['role']!='Teacher')continue; ?>
                            <tr>
                                <td><?= $l['name'] ?></td>
                                <td><span style="color:var(--neon-orange);font-weight:700;font-size:0.75rem;text-transform:uppercase;"><?= $l['role'] ?></span></td>
                                <td><?= date('h:i A',strtotime($l['time_in'])) ?></td>
                                <td><?= $l['time_out']?date('h:i A',strtotime($l['time_out'])):'--' ?></td>
                                <td><span style="color:<?= $l['time_out']?'var(--text-muted)':'var(--neon-green)' ?>;border:1px solid currentColor;padding:3px 10px;border-radius:20px;font-size:0.7rem;"><?= $l['time_out']?'COMPLETED':'ACTIVE' ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="depedModal" class="modal">
        <div class="modal-content large">
            <span class="close" onclick="hideModal('depedModal')">&times;</span>
            <h3 style="background:linear-gradient(to right,#ffd700,#ffcc00);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">Latest DepEd Announcements</h3>
            
            <div class="news-list" style="max-height: 500px; overflow-y: auto; padding-right: 5px;">
                <?php if(!empty($deped_news)): ?>
                    <?php foreach($deped_news as $news): ?>
                        <a href="<?= $news['link'] ?>" target="_blank" class="news-item">
                            <div class="news-date">
                                <i class="fas fa-calendar-alt"></i> <?= $news['date'] ?>
                            </div>
                            <div class="news-title"><?= $news['title'] ?></div>
                            <?php if(!empty($news['desc'])): ?>
                                <div class="news-desc"><?= substr($news['desc'], 0, 150) ?>...</div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align:center; padding: 40px; color: #888;">
                        <i class="fas fa-wifi" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                        Unable to load feed. Please check internet connection.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="suspensionModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('suspensionModal')">&times;</span>
            <h3 style="margin-bottom:20px; text-align:center;">Class Suspension Status</h3>
            
            <?php if($has_suspension): ?>
                <div class="suspension-box suspension-red">
                    <i class="fas fa-exclamation-triangle sus-icon"></i>
                    <h1 class="sus-title">WALANG PASOK</h1>
                    <p class="sus-desc">Classes are suspended.</p>
                    <div class="sus-info">
                        <strong>Source:</strong> DepEd / News Feed<br>
                        "<?= $suspension_msg ?>"
                    </div>
                </div>
            <?php else: ?>
                <div class="suspension-box suspension-green">
                    <i class="fas fa-check-circle sus-icon"></i>
                    <h1 class="sus-title">MAY PASOK</h1>
                    <p class="sus-desc">Regular Classes Today.</p>
                    <div class="sus-info">
                        No suspension announcement found in recent news.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function showModal(id){document.getElementById(id).style.display='flex'}
        function hideModal(id){document.getElementById(id).style.display='none'}
        
        function openUpdStudent(d){document.getElementById('us_id').value=d.student_id;document.getElementById('us_lrn').value=d.lrn;document.getElementById('us_name').value=d.name;document.getElementById('us_gen').value=d.gender;document.getElementById('us_email').value=d.email;document.getElementById('us_con').value=d.contact_number;document.getElementById('us_add').value=d.address;showModal('updStudent');}
        function openUpdTeacher(d){document.getElementById('ut_id').value=d.teacher_id;document.getElementById('ut_name').value=d.name;document.getElementById('ut_gen').value=d.gender;document.getElementById('ut_email').value=d.email;document.getElementById('ut_con').value=d.contact_number;document.getElementById('ut_add').value=d.address;showModal('updTeacher');}
        function filterTable(inId, tblId) {let filter = document.getElementById(inId).value.toUpperCase();let tr = document.getElementById(tblId).getElementsByTagName('tr');for(let i=1; i<tr.length; i++) {let td = tr[i].getElementsByTagName('td')[2]; if(td) tr[i].style.display = td.innerText.toUpperCase().indexOf(filter) > -1 ? '' : 'none';}}
        function filterDivList(inId, divId) {let filter = document.getElementById(inId).value.toUpperCase();let items = document.getElementById(divId).getElementsByClassName('list-item');for(let i=0; i<items.length; i++) {let txt = items[i].innerText;if(txt.toUpperCase().indexOf(filter) > -1) {items[i].style.display = "flex";} else {items[i].style.display = "none";}}}
        function switchTab(t){document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));if(t==='stu') {document.querySelector('.tab-btn:first-child').classList.add('active');document.getElementById('log-stu').style.display='block';document.getElementById('log-tch').style.display='none';} else {document.querySelector('.tab-btn:last-child').classList.add('active');document.getElementById('log-stu').style.display='none';document.getElementById('log-tch').style.display='block';}}
        function toggleQrList(v){if(v === 'stu') {document.getElementById('qr-stu-list').style.display = 'flex';document.getElementById('qr-tch-list').classList.add('hidden');document.getElementById('qr-tch-list').style.display = 'none';} else {document.getElementById('qr-stu-list').style.display = 'none';document.getElementById('qr-tch-list').classList.remove('hidden');document.getElementById('qr-tch-list').style.display = 'flex';}}
        var qrobj;
        function makeQR(d,n){ document.getElementById('qrcode').innerHTML=""; qrobj=new QRCode(document.getElementById("qrcode"),{text:d,width:150,height:150,colorDark:"#000000",colorLight:"#ffffff"}); document.getElementById('qrName').innerText=n; }
        function downloadQR(){ let img=document.querySelector("#qrcode img"); if(img){let a=document.createElement("a");a.href=img.src;a.download="QR.png";a.click();} }
        let html5QrcodeScanner;
        function openScanner(){ showModal('scanModal'); html5QrcodeScanner = new Html5QrcodeScanner("reader", {fps:10,qrbox:250}); html5QrcodeScanner.render(onScanSuccess); }
        function closeScanner(){ hideModal('scanModal'); if(html5QrcodeScanner)html5QrcodeScanner.clear(); }
        function onScanSuccess(t){let fd = new FormData(); fd.append('action','log_attendance'); fd.append('qr_data',t);fetch('index.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{document.getElementById('scanResult').innerHTML = d.status==='success' ? `<span style='color:#00ff9d; font-weight:bold; font-size:1.2rem;'>${d.type}: ${d.name}</span>` : `<span style='color:#ff3b3b; font-weight:bold;'>${d.message}</span>`;});}

        // --- CHART JS SETUP ---
        document.addEventListener("DOMContentLoaded", function() {
            const ctx = document.getElementById('popChart');
            if(ctx) {
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Students', 'Teachers'],
                        datasets: [{
                            data: [<?= $count_students ?>, <?= $count_teachers ?>],
                            backgroundColor: ['#00ff9d', '#00d2ff'],
                            borderColor: '#050511',
                            borderWidth: 5,
                            hoverOffset: 10
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { color: '#ffffff', font: { family: 'Outfit', size: 14 }, padding: 20 }
                            }
                        },
                        layout: { padding: 20 }
                    }
                });
            }

            // --- AUTO HIDE FLASH MESSAGES ---
            const flashMsg = document.querySelector('.msg');
            if(flashMsg){
                setTimeout(()=>{
                    flashMsg.classList.add('msg-hide');
                    setTimeout(()=>{ flashMsg.remove(); }, 1000); 
                }, 20000); 
            }
        });

        // --- WEATHER WIDGET LOGIC ---
        const weatherKey = '1b2d3ca08b8afd12475cdd034b660e2f'; // <--- PASTE YOUR API KEY HERE
        const weatherCity = 'Manila';

        async function initWeather() {
            updateWidgetTime();
            setInterval(updateWidgetTime, 1000);
            
            // REMOVED BLOCKING IF STATEMENT
            // if(weatherKey === '1b2d3ca08b8afd12475cdd034b660e2f') { document.getElementById('w-desc').innerText = "Set API Key"; return; }

            try {
                const url = `https://api.openweathermap.org/data/2.5/weather?q=${weatherCity},ph&units=metric&appid=${weatherKey}`;
                const res = await fetch(url);
                const data = await res.json();
                if(data.cod === 200) {
                    document.getElementById('w-temp').innerText = Math.round(data.main.temp) + "°";
                    document.getElementById('w-desc').innerText = data.weather[0].description;
                    document.getElementById('w-city').innerText = data.name;
                    const iconCode = data.weather[0].icon;
                    document.getElementById('w-icon').src = `https://openweathermap.org/img/wn/${iconCode}@2x.png`;
                    document.getElementById('w-icon').style.display = 'block';
                }
            } catch (err) {
                console.log("Weather Error:", err);
                document.getElementById('w-desc').innerText = "Offline";
            }
        }

        function updateWidgetTime() {
            const now = new Date();
            if(document.getElementById('clock')) { document.getElementById('clock').innerText = now.toLocaleTimeString(); }
            const opts = { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
            if(document.getElementById('w-date')) { document.getElementById('w-date').innerText = now.toLocaleDateString('en-PH', opts); }
        }

        document.addEventListener("DOMContentLoaded", initWeather);
    </script>
<?php endif; ?>
</body>
</html>