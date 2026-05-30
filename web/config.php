<?php
/**
 * OurTours - Database Configuration
 * データベース接続情報を設定してください
 */

// データベース設定
define('DB_HOST', 'localhost');           // DBアドレス
define('DB_PORT', '3306');                 // DBポート
define('DB_NAME', 'minecraft');            // DB名前
define('DB_USER', 'root');                 // DBユーザー名
define('DB_PASS', '');                     // DBパスワード
define('DB_TABLE', 'ourtours'); // DBテーブル名

// CSRFトークンのキー
define('CSRF_TOKEN_KEY', 'tour_csrf_token');

// タイムゾーン設定
date_default_timezone_set('Asia/Tokyo');

// エラー表示設定（本番環境では0に設定してください）
error_reporting(E_ALL);
ini_set('display_errors', 1);
