<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

if (wp_doing_ajax()) {
  add_action('wp_ajax_send_estimate_message', 'send_estimate_message_handler');
  add_action('wp_ajax_nopriv_send_estimate_message', 'send_estimate_message_handler');

  function send_estimate_message_handler(){
    check_ajax_referer('pvr_nonce', 'nonce_code');
    
    $project_type = '';
    if (!empty($_POST['project_type']) && is_string($_POST['project_type'])) {
      $project_type = wp_strip_all_tags($_POST['project_type']);
    }

    $project_name = '';
    if (!empty($_POST['project_name']) && is_string($_POST['project_name'])) {
      $project_name = wp_strip_all_tags($_POST['project_name']);
    }

    $budget = '';
    if (!empty($_POST['budget']) && is_string($_POST['budget'])) {
      $budget = wp_strip_all_tags($_POST['budget']);
    }

    $fullname = '';
    if (!empty($_POST['fullname']) && is_string($_POST['fullname'])) {
      $fullname = wp_strip_all_tags($_POST['fullname']);
    }

    $email = '';
    if (!empty($_POST['email']) && is_string($_POST['email']) && is_email($_POST['email'])) {
      $email = wp_strip_all_tags($_POST['email']);
    }

    $phone = '';
    if (!empty($_POST['phone']) && is_string($_POST['phone'])) {
      $phone = wp_strip_all_tags($_POST['phone']);
    }

    $message = '';
    if (!empty($_POST['message']) && is_string($_POST['message'])) {
      $message = wp_strip_all_tags($_POST['message']);
    }

    /* file uploading */
    $files = array();
    
    $attachment = array();
    if (
      !empty($_FILES) 
      && !empty($_FILES['files']) 
      ){
      $files = est_upload_files($_FILES['files']);

      foreach($files as $one_file){
        $attachment[] = $one_file['path'];
      }
    }

    $token = '';
    if (!empty($_POST['token']) && is_string($_POST['token'])) {
      $token = $_POST['token'];
    }

    if (
      empty($project_type) ||
      empty($project_name) ||
      empty($fullname) ||
      empty($email) ||
      empty($message) ||
      empty($token)
    ) {
      header400();
      header_json();
      echo json_encode([
        'status' => 'fail',
        'code' => 'wrong_post_data'
      ]);
      die();
    }

    $recaptcha_resp = verify_google_recaptcha(
      get_option('recaptcha_secret'),
      $token,
      $_SERVER['REMOTE_ADDR']
    );

    if (!check_google_recaptcha_response($recaptcha_resp, 'estimate_message', RECAPTCHA_SCORE_LEVEL)) {
      header200();
      header_json();
      echo json_encode([
        'status' => 'fail',
        'code' => 'bot_suspicion'
      ]);
      die();
    }

    if (!estimate_contact_message(
      $project_type,
      $project_name,
      $fullname,
      $email,
      $phone,
      $message,
      $files
    )) {
      header400();
      header_json();
      echo json_encode([
        'status' => 'fail',
        'code' => 'wrong_post_creating'
      ]);
      die();
    }

    
    // sending email
    $opt_email = get_option('email');
    $opt_emailto = get_option('emailto');
    $opt_email_info = get_option('email_info');
    $opt_email_debug = get_option('email_debug');

    $to = [];
    if (!empty($opt_email) && is_email($opt_email)) {
      $to[] = $opt_email;
    }
    if (!empty($opt_emailto) && is_email($opt_emailto)) {
      $to[] = $opt_emailto;
    }
    if (!empty($opt_email_info) && is_email($opt_email_info)) {
      $to[] = $opt_email_info;
    }
    if (!empty($opt_email_debug) && is_email($opt_email_debug)) {
      $to[] = $opt_email_debug;
    }

    if (!empty($to)) {
      $email_subject = 'Estimation message "' . get_bloginfo('name') . '" ' . $project_name;

      $email_text = "<table>";
      $email_text .= "<tr><td><strong>Project name: </strong></td><td>$project_name</td></tr>";
      $email_text .= "<tr><td><strong>Project type: </strong></td><td>$project_type</td></tr>";
      $email_text .= "<tr><td><strong>Name: </strong></td><td>$fullname</td></tr>";
      $email_text .= "<tr><td><strong>Email: </strong></td><td>$email</td></tr>";
      $email_text .= "<tr><td><strong>Phone: </strong></td><td>$phone</td></tr>";
      $email_text .= "<tr><td><strong>Message: </strong></td><td>$message</td></tr>";
      $email_text .= "</table>";

      if (!send_html_email($to, $opt_email_info, $email_subject, $email_text, $attachment)) {
        if (WP_DEBUG) {
          header400();
          header_json();
          echo json_encode([
            'status' => 'fail',
            'code' => 'wrong_sending_email'
          ]);
          die();
        }
      }
    }

    /* create post */

    header200();
    header_json();
    echo json_encode([
      'status' => 'success'
    ]);
    die();

  }

  function est_validate_file($file_name, $file_error, $file_size, $file_path ){
    try {
      if(!$file_name) throw new RuntimeException('No file sent.');

      if (
        !isset($file_error) ||
        is_array($file_error)
      ) {
        throw new RuntimeException('Invalid parameters.');
      }

      switch ($file_error) {
        case UPLOAD_ERR_OK:
          break;
        case UPLOAD_ERR_NO_FILE:
          throw new RuntimeException('No file sent.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
          throw new RuntimeException('Exceeded filesize limit.');
        default:
          throw new RuntimeException('Unknown errors.');
      }

      if ($file_size > 2147483648) {
        throw new RuntimeException('Exceeded filesize limit.');
      }

      $finfo = new finfo(FILEINFO_MIME_TYPE);
      if (false === $ext = array_search(
          $finfo->file($file_path),
          array(
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'svg' => 'image/svg+xml',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'html' => 'text/html',
            'zip' => 'application/zip'
          ),
          true
      )) {
        throw new RuntimeException('Invalid file format.');
      }
    } catch (RuntimeException $e) {
      return false;
    }

    return true;
  }

  function est_upload_files($files){
    $files_info = array();
    $upload_dir_info = est_create_folder();

    foreach($files["name"] as $key => $one_file){
      if(!est_validate_file($files["name"][$key], $files['error'][$key], $files['size'][$key], $files['tmp_name'][$key])) continue;
      $upload_path = $upload_dir_info['path'] . time() . "_" . $one_file;
      $upload_url = $upload_dir_info['url'] . time() . "_" . $one_file;

      move_uploaded_file($files['tmp_name'][$key], $upload_path);

      $files_info[] = array(
        'path' => $upload_path,
        'url' => $upload_url,
        'name' => $one_file
      );
    }

    return $files_info;
  }

  function est_create_folder(){
    $upload_dir_info = wp_upload_dir();

    $today = time();
    $dir = date('d', $today) . '/';

    $upload_path  = $upload_dir_info['path'] . "/" . $dir;
    $upload_url = $upload_dir_info['url'] . "/" . $dir;

    if(!is_dir($upload_path)){
      mkdir ($upload_path, 0777, true);
    }

		return array(
      'path' => $upload_path,
      'url' => $upload_url
    );
  }

  function estimate_contact_message(
    string $project_type,
    string $project_name,
    string $fullname,
    string $email,
    string $phone,
    string $message,
    array $files
  ) {
    $project_type = sanitize_text_field($project_type);
    $project_name = sanitize_text_field($project_name);
    $fullname = sanitize_text_field($fullname);
    $email = sanitize_text_field($email);
    $phone = sanitize_text_field($phone);
    $message = sanitize_text_field($message);
  
    if (
      empty($project_name) ||
      empty($project_type) ||
      empty($fullname) ||
      empty($email) || !is_email($email) ||
      empty($message)
    ) {
      return false;
    }
  
    $post_id = wp_insert_post([
      'post_title' => $project_name . ' ' . current_time('mysql', 1),
      'post_status' => 'publish',
      'post_type' => 'cp_estimate_message'
    ]);
  
    if (empty($post_id) || !is_index($post_id)) {
      return false;
    }

    update_post_meta($post_id, 'project_type', $project_type);
    update_post_meta($post_id, 'fullname', $fullname);
    update_post_meta($post_id, 'email', $email);
    update_post_meta($post_id, 'phone', $phone);
    update_post_meta($post_id, 'message', $message);
    update_post_meta($post_id, 'files', $files);
  
    return $post_id;
  }
}