<?php
/**
 * WHMCS WhatsApp Notification Module
 * This is the ADDON MODULE file - place in /modules/addons/whatsapp_notification/
 * The hooks file is separate: /includes/hooks/whatsapp_hooks.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Module Configuration
 */
function whatsapp_notification_config() {
    return array(
        'name' => 'WhatsApp Notification',
        'description' => 'Send WhatsApp notifications for WHMCS events using MessageMarvel API',
        'version' => '1.0',
        'author' => 'Message Marvel',
        'fields' => array(
            'whatsapp_api_token' => array(
                'FriendlyName' => 'WhatsApp API Token',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Get your API token by signing up for an account at <a href="https://messagemarvel.com/" target="_blank">https://messagemarvel.com/</a> (Available in Pro and Enterprise Plans)'
            ),
            'support_phone_number' => array(
                'FriendlyName' => 'Support Phone Number',
                'Type' => 'text',
                'Size' => '20',
                'Default' => '',
                'Description' => 'Your support phone number (will be included in WhatsApp messages)'
            ),
            'website_url' => array(
                'FriendlyName' => 'Website URL',
                'Type' => 'text',
                'Size' => '50',
                'Default' => '',
                'Description' => 'Your website URL (used for payment links)'
            ),
            'company_name' => array(
                'FriendlyName' => 'Company Name',
                'Type' => 'text',
                'Size' => '30',
                'Default' => '',
                'Description' => 'Your company name for message footers'
            ),
            'enable_invoice_created' => array(
                'FriendlyName' => 'Invoice Generation Alert',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Send alert when invoice is created'
            ),
            'enable_invoice_paid' => array(
                'FriendlyName' => 'Payment Confirmation Notice',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Send confirmation when invoice is paid'
            ),
            'enable_invoice_due' => array(
                'FriendlyName' => 'Invoice Due Reminder',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Send reminder when invoice is due'
            ),
            'enable_service_suspension' => array(
                'FriendlyName' => 'Service Suspension Alert',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Send alert when service is suspended'
            ),
            'enable_service_activation' => array(
                'FriendlyName' => 'Service Activation Notice',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Send notification when service is activated'
            )
        )
    );
}

/**
 * Module activation
 */
function whatsapp_notification_activate() {
    return array('status' => 'success', 'description' => 'WhatsApp Notification module activated successfully.');
}

/**
 * Module deactivation
 */
function whatsapp_notification_deactivate() {
    return array('status' => 'success', 'description' => 'WhatsApp Notification module deactivated.');
}

/**
 * Admin area output
 */
function whatsapp_notification_output($vars) {
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3>WhatsApp Notification Settings</h3></div>';
    echo '<div class="panel-body">';
    echo '<p>Configure your WhatsApp notification settings below. All notifications are sent using your MessageMarvel API with professional, consistent messaging.</p>';
    
    echo '<div class="alert alert-info">';
    echo '<h4>More Information</h4>';
    echo '<p>Visit <a href="https://messagemarvel.com/" target="_blank">https://messagemarvel.com/</a> for API documentation and support.</p>';
    echo '</div>';
    
    // Test message form
    echo '<div class="alert alert-info">';
    echo '<h4>Test Message</h4>';
    echo '<form method="post">';
    echo '<div class="form-group">';
    echo '<label>Phone Number (with country code):</label>';
    echo '<input type="text" name="test_phone" class="form-control" placeholder="+254712345678" required>';
    echo '</div>';
    echo '<button type="submit" name="send_test" class="btn btn-primary">Send Test Message</button>';
    echo '</form>';
    echo '</div>';
    
    // Handle test message
    if (isset($_POST['send_test'])) {
        $test_phone = $_POST['test_phone'];
        $result = whatsapp_send_test_message($test_phone, $vars);
        if ($result) {
            echo '<div class="alert alert-success">Test message sent successfully!</div>';
        } else {
            echo '<div class="alert alert-danger">Failed to send test message. Check your API settings.</div>';
        }
    }
    
    echo '</div>';
    echo '</div>';
}

/**
 * Test message function - ONLY used by the addon module
 */
function whatsapp_send_test_message($phone, $vars) {
    // Clean phone number
    $phoneNumber = preg_replace('/[^0-9+]/', '', $phone);
    if (!str_starts_with($phoneNumber, '+')) {
        $phoneNumber = '+254' . ltrim($phoneNumber, '0');
    }
    
    // Get API token from settings
    $api_token = $vars['whatsapp_api_token'] ?? '';
    if (empty($api_token)) {
        return false;
    }
    
    // Create test message
    $message = "🧪 This is a test message from your WHMCS WhatsApp notification system!\n\n";
    $message .= "If you received this, everything is working perfectly! ✅\n\n";
    $message .= "📞 Need help? Contact support: " . ($vars['support_phone_number'] ?? 'YOUR_SUPPORT_NUMBER') . "\n";
    $message .= "⚠️ This is an automated notification - please call for assistance";
    
    $data = [
        'phone' => $phoneNumber,
        'message' => $message,
        'header' => '🧪 Test Message',
        'footer' => 'Powered by ' . ($vars['company_name'] ?? 'Your Company')
    ];
    
    // Send test message
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://messagemarvel.com/api/send');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        logActivity("WhatsApp Test Message Sent: {$phoneNumber}");
        return true;
    }
    
    return false;
}

?>
