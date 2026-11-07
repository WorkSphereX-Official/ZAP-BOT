<?php
/**
 * Single-File PHP Telegram Bot + ZapUPI Payment Gateway Integration
 * Optimized for Screen Resolution: 720x1600 (Samsung F22 4G)
 */

// ==========================================
// CONFIGURATION CONSTANTS
// ==========================================
define('TELEGRAM_BOT_TOKEN', '9223972060:AAEdH9Oecjw7KPRi2sXJZ8L8kLPLUPY2f44');
define('TELEGRAM_BOT_USERNAME', 'Zaptesttt_bot'); // Without @
define('ZAP_KEY', 'zap8ddd001c0a720843777b4efad00f6e26');
define('CUSTOMER_MOBILE', '9999999999');

// ==========================================
// 1. ROUTING: WEBHOOK vs TELEGRAM UPDATE
// ==========================================
if (isset($_GET['zapupi']) && $_GET['zapupi'] === 'webhook') {
    handleZapApiWebhook();
    exit;
}

handleTelegramUpdate();
exit;

// ==========================================
// TELEGRAM UPDATE HANDLING
// ==========================================
function handleTelegramUpdate() {
    $content = file_get_contents("php://input");
    $update = json_decode($content, true);

    if (!$update) {
        return;
    }

    if (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
        return;
    }

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = trim($message['text'] ?? '');

        if (isset($message['reply_to_message'])) {
            handleForceReplyMessage($message);
            return;
        }

        if (strpos($text, '/start') === 0) {
            $parts = explode(' ', $text);
            if (isset($parts[1])) {
                $payload = htmlspecialchars($parts[1]);
                $msg = "✨ **Welcome back!**\n\nPayload: `$payload`.\n\n_Final confirmation arrives via webhook!_ 🚀";
                sendTelegramMessage($chat_id, $msg);
            } else {
                $msg = "👋 **Welcome to OWNER SUPPORT PLACE**\n\n💰 How much do you want to pay?\n\n_Please send the numeric amount (e.g. 100):_";
                sendTelegramMessage($chat_id, $msg);
            }
            return;
        }

        if (is_numeric($text) && floatval($text) > 0) {
            createZapOrder($chat_id, floatval($text));
            return;
        }

        $msg = "🤖 Please type `/start` to begin or enter a valid payment amount.";
        sendTelegramMessage($chat_id, $msg);
    }
}

// ==========================================
// ZAP-UPI ORDER CREATION (FIXED JSON FORMAT)
// ==========================================
function createZapOrder($chat_id, $amount) {
    $order_id = $chat_id . 'x' . time();

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $script_path = $_SERVER['SCRIPT_NAME'];
    $webhook_url = $protocol . $host . $script_path . "?zapupi=webhook";

    $success_url = "https://t.me/" . TELEGRAM_BOT_USERNAME . "?start=success_" . $order_id;
    $failed_url  = "https://t.me/" . TELEGRAM_BOT_USERNAME . "?start=failed_" . $order_id;
    $timeout_url = "https://t.me/" . TELEGRAM_BOT_USERNAME . "?start=timeout_" . $order_id;

    $postData = [
        'zap_key'         => ZAP_KEY,
        'order_id'        => $order_id,
        'amount'          => $amount,
        'customer_mobile' => CUSTOMER_MOBILE,
        'remark'          => 'Order-' . $chat_id,
        'success_url'     => $success_url,
        'failed_url'      => $failed_url,
        'timeout_url'     => $timeout_url,
        'webhook_url'     => $webhook_url
    ];

    // Encode as strict JSON payload for ZapUPI API requirements
    $jsonData = json_encode($postData);

    $ch = curl_init('https://pay.zapupi.com/api/create-order');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonData)
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $resData = json_decode($response, true);

    if (!$resData || (isset($resData['status']) && $resData['status'] === 'error')) {
        $errorMsg = $resData['message'] ?? 'Failed to communicate with payment gateway.';
        sendTelegramMessage($chat_id, "❌ **Error:** " . $errorMsg);
        return;
    }

    if (isset($resData['status']) && $resData['status'] === 'success') {
        if (!empty($resData['pay_id'])) {
            $pay_id = $resData['pay_id'];
            $img_url = $resData['payment_image_url'] ?? '';
            
            $caption = "🏷 **Order ID:** `$order_id`\n" .
                       "💵 **Amount:** ₹$amount\n\n" .
                       "📲 **Scan the QR code below to pay.**";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔍 Check Payment', 'callback_data' => 'check_' . $pay_id],
                        ['text' => '✍️ Enter UTR', 'callback_data' => 'utr_' . $pay_id]
                    ]
                ]
            ];

            if (!empty($img_url)) {
                sendTelegramPhoto($chat_id, $img_url, $caption, $keyboard);
            } else {
                sendTelegramMessage($chat_id, $caption, $keyboard);
            }

        } else {
            $payment_url = $resData['payment_url'] ?? '#';
            
            $msg = "✅ **Order Created Successfully!**\n\n" .
                   "🏷 **Order ID:** `$order_id`\n" .
                   "💵 **Amount:** ₹$amount\n\n" .
                   "Click below to proceed:";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '💳 Pay Now', 'url' => $payment_url]
                    ],
                    [
                        ['text' => '❌ Cancel Payment', 'callback_data' => 'cancel_order']
                    ]
                ]
            ];

            sendTelegramMessage($chat_id, $msg, $keyboard);
        }
    } else {
        sendTelegramMessage($chat_id, "⚠️ Unexpected response from payment provider.");
    }
}

// ==========================================
// CALLBACK & UTR HANDLING
// ==========================================
function handleCallbackQuery($callback) {
    $callback_id = $callback['id'];
    $chat_id = $callback['message']['chat']['id'];
    $data = $callback['data'];

    if ($data === 'cancel_order') {
        answerCallbackQuery($callback_id, "Payment cancelled");
        sendTelegramMessage($chat_id, "🚫 **Payment cancelled.** Send `/start` to try again.");
        return;
    }

    if (strpos($data, 'check_') === 0) {
        $pay_id = substr($data, 6);
        answerCallbackQuery($callback_id, "Checking status...");
        
        $url = "https://pay.zapupi.com/pay/auto-check-" . $pay_id;
        $response = @file_get_contents($url);
        $resData = json_decode($response, true);
        
        $status = strtoupper($resData['status'] ?? 'PENDING');
        
        switch ($status) {
            case 'SUCCESS':
                $reply = "✅ **UI Preview:** Payment received! (Awaiting webhook confirmation)";
                break;
            case 'FAILED':
                $reply = "❌ **UI Preview:** Payment failed.";
                break;
            case 'TIMEOUT':
                $reply = "⏰ **UI Preview:** Payment timed out.";
                break;
            default:
                $reply = "⏳ **UI Preview:** Not received yet, check again shortly.";
                break;
        }
        sendTelegramMessage($chat_id, $reply);
        return;
    }

    if (strpos($data, 'utr_') === 0) {
        $pay_id = substr($data, 4);
        answerCallbackQuery($callback_id, "Enter UTR");
        sendForceReplyMessage($chat_id, "✍️ **Reply with your UTR/reference number**\n\n(Pay ID Reference: `$pay_id`)");
        return;
    }
}

function handleForceReplyMessage($message) {
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $reply_text = $message['reply_to_message']['text'] ?? '';

    if (preg_match('/Reference: `([^`]+)`/', $reply_text, $matches)) {
        $pay_id = $matches[1];
        $utr = $text;

        $url = "https://pay.zapupi.com/pay/utr-check-" . $pay_id . "-" . urlencode($utr);
        $response = @file_get_contents($url);
        $resData = json_decode($response, true);
        $status = strtoupper($resData['status'] ?? 'PENDING');

        switch ($status) {
            case 'SUCCESS':
                $reply = "✅ **UTR UI Preview:** Verified! (Awaiting webhook)";
                break;
            case 'FAILED':
                $reply = "❌ **UTR UI Preview:** Verification failed.";
                break;
            case 'TIMEOUT':
                $reply = "⏰ **UTR UI Preview:** Timed out.";
                break;
            default:
                $reply = "⏳ **UTR UI Preview:** Not received yet for UTR `$utr`.";
                break;
        }
        sendTelegramMessage($chat_id, $reply);
    } else {
        sendTelegramMessage($chat_id, "⚠️ Could not associate UTR with session.");
    }
}

// ==========================================
// WEBHOOK HANDLING
// ==========================================
function handleZapApiWebhook() {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!$data || !isset($data['order_id']) || !isset($data['status'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid webhook payload']);
        exit;
    }

    $order_id = $data['order_id'];
    $status = strtolower($data['status']);
    $utr = $data['utr'] ?? '';

    $parts = explode('x', $order_id);
    $chat_id = $parts[0] ?? null;

    if (!$chat_id || !is_numeric($chat_id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid chat_id']);
        exit;
    }

    $msg = "";
    if ($status === 'success' || $status === 'paid') {
        $msg = "✅ **Payment Successful! (Verified via Webhook)**\n\n" .
               "🏷 **Order ID:** `$order_id`\n" .
               (!empty($utr) ? "🔑 **UTR:** `$utr`\n" : "") .
               "\n_Thank you! Order confirmed._ 🎉";
    } elseif ($status === 'failed') {
        $msg = "❌ **Payment Failed**\n\n🏷 **Order ID:** `$order_id`\n\n_Send /start to try again._";
    } elseif ($status === 'timeout') {
        $msg = "⏰ **Payment Timed Out**\n\n🏷 **Order ID:** `$order_id`\n\n_Send /start to try again._";
    } else {
        $msg = "ℹ️ **Payment Update**\n\n🏷 **Order ID:** `$order_id`\n📌 **Status:** `" . htmlspecialchars($status) . "`";
    }

    sendTelegramMessage($chat_id, $msg);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
    exit;
}

// ==========================================
// TELEGRAM API HELPERS
// ==========================================
function sendTelegramMessage($chat_id, $text, $reply_markup = null) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $postData = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($reply_markup) $postData['reply_markup'] = json_encode($reply_markup);
    callTelegramApi($url, $postData);
}

function sendTelegramPhoto($chat_id, $photo_url, $caption, $reply_markup = null) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendPhoto";
    $postData = ['chat_id' => $chat_id, 'photo' => $photo_url, 'caption' => $caption, 'parse_mode' => 'Markdown'];
    if ($reply_markup) $postData['reply_markup'] = json_encode($reply_markup);
    callTelegramApi($url, $postData);
}

function sendForceReplyMessage($chat_id, $text) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $postData = [
        'chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'Markdown',
        'reply_markup' => json_encode(['force_reply' => true, 'selective' => true])
    ];
    callTelegramApi($url, $postData);
}

function answerCallbackQuery($callback_query_id, $text) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/answerCallbackQuery";
    $postData = ['callback_query_id' => $callback_query_id, 'text' => $text, 'show_alert' => false];
    callTelegramApi($url, $postData);
}

function callTelegramApi($url, $postData) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_exec($ch);
    curl_close($ch);
}