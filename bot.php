<?php
/**
 * Single-File PHP Telegram Bot + ZapUPI Payment Gateway Integration
 * 
 * Screen Resolution Optimized: 720x1600 (Samsung F22 4G)
 * 
 * Instructions:
 * 1. Fill in your configuration constants below.
 * 2. Upload this file to your server (e.g., bot.php via HTTPS).
 * 3. Set your Telegram Webhook (WITHOUT query parameters): 
 *    https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=https://yourdomain.com/bot.php
 * 4. ZapUPI Webhook URL will automatically point to: https://yourdomain.com/bot.php?zapupi=webhook
 */

// ==========================================
// CONFIGURATION CONSTANTS
// ==========================================
define('TELEGRAM_BOT_TOKEN', '9223972060:AAEdH9Oecjw7KPRi2sXJZ8L8kLPLUPY2f44');
define('TELEGRAM_BOT_USERNAME', 'Zaptesttt_bot'); // Without @
define('ZAP_KEY', 'zap8ddd001c0a720843777b4efad00f6e26');
define('CUSTOMER_MOBILE', '9999999999'); // Default/dummy customer mobile

// ==========================================
// 1. ROUTING: WEBHOOK vs TELEGRAM UPDATE
// ==========================================
if (isset($_GET['zapupi']) && $_GET['zapupi'] === 'webhook') {
    handleZapApiWebhook();
    exit;
}

// Otherwise, process incoming Telegram Update
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

    // Handle Callback Queries (Inline Buttons)
    if (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
        return;
    }

    // Handle Standard Messages
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = trim($message['text'] ?? '');

        // Handle ForceReply (User entering UTR)
        if (isset($message['reply_to_message'])) {
            handleForceReplyMessage($message);
            return;
        }

        // Handle /start command
        if (strpos($text, '/start') === 0) {
            $parts = explode(' ', $text);
            if (isset($parts[1])) {
                // Deep link return (e.g. start=success_ORDERID)
                $payload = htmlspecialchars($parts[1]);
                $msg = "✨ **Welcome back!**\n\nYour payment reference payload: `$payload`.\n\n_Note: Final status confirmation arrives shortly via webhook!_ 🚀";
                sendTelegramMessage($chat_id, $msg);
            } else {
                // Ask for amount
                $msg = "👋 **Welcome to ZapUPI Bot!**\n\n💰 How much do you want to pay?\n\n_Please send the numeric amount below (e.g. 100):_";
                sendTelegramMessage($chat_id, $msg);
            }
            return;
        }

        // Handle State: User typing payment amount right after /start
        if (is_numeric($text) && floatval($text) > 0) {
            createZapOrder($chat_id, floatval($text));
            return;
        }

        // Fallback for unexpected text
        $msg = "🤖 Please type `/start` to begin or enter a valid payment amount.";
        sendTelegramMessage($chat_id, $msg);
    }
}

// ==========================================
// ZAP-UPI ORDER CREATION
// ==========================================
function createZapOrder($chat_id, $amount) {
    // Generate unique order_id embedding chat_id (e.g., chat_id x timestamp)
    $order_id = $chat_id . 'x' . time();

    // Auto-detect webhook URL from current domain/script path
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $script_path = $_SERVER['SCRIPT_NAME'];
    $webhook_url = $protocol . $host . $script_path . "?zapupi=webhook";

    // URLs to return back to the bot via deep links
    $success_url = "https://t.me/" . TELEGRAM_BOT_USERNAME . "?start=success_" . $order_id;
    $failed_url  = "https://t.me/" . TELEGRAM_BOT_USERNAME . "?start=failed_" . $order_id;
    $timeout_url = "https://t.me/" . TELEGRAM_BOT_USERNAME . "?start=timeout_" . $order_id;

    $postData = [
        'zap_key'       => ZAP_KEY,
        'order_id'      => $order_id,
        'amount'        => $amount,
        'customer_mobile' => CUSTOMER_MOBILE,
        'remark'        => 'Telegram Order #' . $chat_id,
        'success_url'   => $success_url,
        'failed_url'    => $failed_url,
        'timeout_url'   => $timeout_url,
        'webhook_url'   => $webhook_url
    ];

    $ch = curl_init('https://pay.zapupi.com/api/create-order');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
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
        // Check if pay_id is present (Direct/Custom QR flow)
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
            // Case One: Default payment page flow (payment__url)
            $payment_url = $resData['payment_url'] ?? '#';
            
            $msg = "✅ **Order Created Successfully!**\n\n" .
                   "🏷 **Order ID:** `$order_id`\n" .
                   "💵 **Amount:** ₹$amount\n\n" .
                   "Click below to proceed to payment:";

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
// CALLBACK QUERY HANDLING (Inline Buttons)
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

    // Handle "Check Payment" preview button (Format: check_PAYID)
    if (strpos($data, 'check_') === 0) {
        $pay_id = substr($data, 6);
        answerCallbackQuery($callback_id, "Checking payment status...");
        
        $url = "https://pay.zapupi.com/pay/auto-check-" . $pay_id;
        $response = @file_get_contents($url);
        $resData = json_decode($response, true);
        
        $status = strtoupper($resData['status'] ?? 'PENDING');
        
        // UI / Preview only message
        switch ($status) {
            case 'SUCCESS':
                $reply = "✅ **UI Preview:** Payment received! (Waiting for official webhook confirmation)";
                break;
            case 'FAILED':
                $reply = "❌ **UI Preview:** Payment failed.";
                break;
            case 'TIMEOUT':
                $reply = "⏰ **UI Preview:** Payment time expired.";
                break;
            default:
                $reply = "⏳ **UI Preview:** Payment not received yet, please check again shortly.";
                break;
        }
        sendTelegramMessage($chat_id, $reply);
        return;
    }

    // Handle "Enter UTR" button (Format: utr_PAYID)
    if (strpos($data, 'utr_') === 0) {
        $pay_id = substr($data, 4);
        answerCallbackQuery($callback_id, "Please enter your UTR");
        
        // Send ForceReply message carrying pay_id in text
        $forceReplyMsg = "✍️ **Reply with your UTR/reference number**\n\n(Pay ID Reference: `$pay_id`)";
        sendForceReplyMessage($chat_id, $forceReplyMsg);
        return;
    }
}

// ==========================================
// FORCE REPLY HANDLING (User sending UTR)
// ==========================================
function handleForceReplyMessage($message) {
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $reply_text = $message['reply_to_message']['text'] ?? '';

    // Extract pay_id from the original ForceReply prompt text
    if (preg_match('/Reference: `([^`]+)`/', $reply_text, $matches)) {
        $pay_id = $matches[1];
        $utr = $text;

        // Call UTR check endpoint: https://pay.zapupi.com/pay/utr-check-PAYID-UTR
        $url = "https://pay.zapupi.com/pay/utr-check-" . $pay_id . "-" . urlencode($utr);
        $response = @file_get_contents($url);
        $resData = json_decode($response, true);

        $status = strtoupper($resData['status'] ?? 'PENDING');

        // UI / Preview response handling
        switch ($status) {
            case 'SUCCESS':
                $reply = "✅ **UTR UI Preview:** Payment verified successfully! (Final status awaits webhook)";
                break;
            case 'FAILED':
                $reply = "❌ **UTR UI Preview:** Payment verification failed.";
                break;
            case 'TIMEOUT':
                $reply = "⏰ **UTR UI Preview:** Payment time expired.";
                break;
            default:
                $reply = "⏳ **UTR UI Preview:** Payment not received yet for UTR `$utr`, please check again shortly.";
                break;
        }
        sendTelegramMessage($chat_id, $reply);
    } else {
        sendTelegramMessage($chat_id, "⚠️ Could not associate UTR with active payment session.");
    }
}

// ==========================================
// 2. WEBHOOK HANDLING (ZapUPI Webhook Endpoint)
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
    $status = strtolower($data['status']); // success, failed, timeout, etc.
    $utr = $data['utr'] ?? '';

    // Recover chat_id from order_id (Reverse-parsed: chat_id x timestamp)
    $parts = explode('x', $order_id);
    $chat_id = $parts[0] ?? null;

    if (!$chat_id || !is_numeric($chat_id)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Could not recover chat_id from order_id']);
        exit;
    }

    // Authoritative Status Processing
    $msg = "";
    if ($status === 'success' || $status === 'paid') {
        $msg = "✅ **Payment Successful! (Verified via Webhook)**\n\n" .
               "🏷 **Order ID:** `$order_id`\n" .
               (!empty($utr) ? "🔑 **UTR:** `$utr`\n" : "") .
               "\n_Thank you for your payment! Your order is now confirmed._ 🎉";
    } elseif ($status === 'failed') {
        $msg = "❌ **Payment Failed**\n\n🏷 **Order ID:** `$order_id`\n\n_Please send /start to try again._";
    } elseif ($status === 'timeout') {
        $msg = "⏰ **Payment Timed Out**\n\n🏷 **Order ID:** `$order_id`\n\n_Please send /start to try again._";
    } else {
        $msg = "ℹ️ **Payment Update**\n\n🏷 **Order ID:** `$order_id`\n📌 **Status:** `" . htmlspecialchars($status) . "`";
    }

    // Send final authoritative message to user
    sendTelegramMessage($chat_id, $msg);

    // Return proper HTTP 200 response to ZapUPI
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Webhook processed successfully']);
    exit;
}

// ==========================================
// TELEGRAM API HELper FUNCTIONS
// ==========================================
function sendTelegramMessage($chat_id, $text, $reply_markup = null) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $postData = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    if ($reply_markup) {
        $postData['reply_markup'] = json_encode($reply_markup);
    }
    callTelegramApi($url, $postData);
}

function sendTelegramPhoto($chat_id, $photo_url, $caption, $reply_markup = null) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendPhoto";
    $postData = [
        'chat_id' => $chat_id,
        'photo' => $photo_url,
        'caption' => $caption,
        'parse_mode' => 'Markdown'
    ];
    if ($reply_markup) {
        $postData['reply_markup'] = json_encode($reply_markup);
    }
    callTelegramApi($url, $postData);
}

function sendForceReplyMessage($chat_id, $text) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $postData = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode([
            'force_reply' => true,
            'selective' => true
        ])
    ];
    callTelegramApi($url, $postData);
}

function answerCallbackQuery($callback_query_id, $text) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/answerCallbackQuery";
    $postData = [
        'callback_query_id' => $callback_query_id,
        'text' => $text,
        'show_alert' => false
    ];
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
