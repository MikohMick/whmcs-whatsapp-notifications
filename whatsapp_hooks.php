<?php
/**
 * Email-Based WhatsApp Notifications for WHMCS 8.12.1
 * This hooks into email sending to trigger WhatsApp notifications
 * Replace your /includes/hooks/whatsapp_hooks.php with this
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Hook: Email Pre-Send - Catch when reminder emails are about to be sent
 */
add_hook('EmailPreSend', 1, function($vars) {
    // Check if this is an overdue notice email
    if (in_array($vars['messagename'], ['First Overdue Invoice Notice', 'Second Overdue Invoice Notice', 'Third Overdue Invoice Notice', 'Invoice Payment Reminder'])) {
        
        // Extract invoice ID from merge fields
        $invoiceId = null;
        if (isset($vars['mergefields']['invoice_id'])) {
            $invoiceId = $vars['mergefields']['invoice_id'];
        } elseif (isset($vars['mergefields']['invoiceid'])) {
            $invoiceId = $vars['mergefields']['invoiceid'];
        }
        
        if ($invoiceId) {
            whatsapp_send_overdue_notice($invoiceId, $vars['messagename']);
        }
    }
});

/**
 * Hook: Email Sent - Catch when emails have been successfully sent
 */
add_hook('EmailSent', 1, function($vars) {
    // Check if this is an overdue notice email
    if (in_array($vars['messagename'], ['First Overdue Invoice Notice', 'Second Overdue Invoice Notice', 'Third Overdue Invoice Notice'])) {
        
        // Get client by email
        $client = \WHMCS\Database\Capsule::table('tblclients')
            ->where('email', $vars['email'])
            ->first();
        
        if ($client) {
            // Find the overdue invoice for this client
            $overdueInvoice = \WHMCS\Database\Capsule::table('tblinvoices')
                ->where('userid', $client->id)
                ->where('status', 'Unpaid')
                ->where('duedate', '<', date('Y-m-d'))
                ->orderBy('duedate', 'asc')
                ->first();
            
            if ($overdueInvoice) {
                whatsapp_send_overdue_notice($overdueInvoice->id, $vars['messagename']);
            }
        }
    }
});

/**
 * Send WhatsApp overdue notice
 */
function whatsapp_send_overdue_notice($invoiceId, $emailType) {
    // Get addon settings
    $settings = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', 'whatsapp_notification')
        ->pluck('value', 'setting');
    
    if (($settings['enable_invoice_due'] ?? 'off') !== 'on') {
        return;
    }
    
    // Get invoice details
    $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        return;
    }
    
    // Get client details
    $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    if (!$client || empty($client->phonenumber)) {
        return;
    }
    
    // Get API token
    $api_token = $settings['whatsapp_api_token'] ?? '';
    if (empty($api_token)) {
        return;
    }
    
    // Determine urgency level based on email type
    $urgencyLevel = 1; // Default to first notice
    if (strpos($emailType, 'Second') !== false) {
        $urgencyLevel = 2;
    } elseif (strpos($emailType, 'Third') !== false) {
        $urgencyLevel = 3;
    }
    
    // Check if we already sent WhatsApp for this specific urgency level today
    $today = date('Y-m-d');
    $alreadySent = \WHMCS\Database\Capsule::table('tblactivitylog')
        ->where('description', 'like', "%WhatsApp Overdue Notice Sent%Invoice #{$invoiceId}%Level {$urgencyLevel}%")
        ->where('date', '>=', $today . ' 00:00:00')
        ->exists();
    
    if ($alreadySent) {
        return;
    }
    
    // Clean phone number
    $phoneNumber = preg_replace('/[^0-9+]/', '', $client->phonenumber);
    if (!str_starts_with($phoneNumber, '+')) {
        $phoneNumber = '+254' . ltrim($phoneNumber, '0');
    }
    
    // Calculate days overdue
    $dueDate = new DateTime($invoice->duedate);
    $today = new DateTime();
    $interval = $today->diff($dueDate);
    $daysOverdue = $interval->days;
    
    // Get settings with fallbacks
    $supportPhone = $settings['support_phone_number'] ?? 'YOUR_SUPPORT_NUMBER';
    $websiteUrl = $settings['website_url'] ?? 'https://your-website.com';
    $companyName = $settings['company_name'] ?? 'Your Company';
    
    // Create message based on urgency
    $message = "Hi {$client->firstname}! ";
    
    switch ($urgencyLevel) {
        case 1:
            $message .= "📅\n\n⚠️ PAYMENT OVERDUE\n\n";
            $message .= "📄 Invoice #{$invoice->id} is {$daysOverdue} days overdue\n\n";
            $message .= "💰 Amount Due: {$invoice->total} KSh\n";
            $message .= "📅 Due Date: {$invoice->duedate}\n\n";
            $message .= "Please pay as soon as possible to avoid service interruption.\n\n";
            $header = "⚠️ Payment Overdue";
            break;
            
        case 2:
            $message .= "🚨\n\n🔴 URGENT: PAYMENT OVERDUE\n\n";
            $message .= "📄 Invoice #{$invoice->id} is {$daysOverdue} days overdue\n\n";
            $message .= "💰 Amount Due: {$invoice->total} KSh\n";
            $message .= "📅 Due Date: {$invoice->duedate}\n\n";
            $message .= "⚡ IMMEDIATE PAYMENT REQUIRED to avoid service suspension.\n\n";
            $header = "🚨 Urgent Payment Required";
            break;
            
        case 3:
            $message .= "🚨\n\n🔴 FINAL NOTICE - SERVICE SUSPENSION IMMINENT\n\n";
            $message .= "📄 Invoice #{$invoice->id} is {$daysOverdue} days overdue\n\n";
            $message .= "💰 Amount Due: {$invoice->total} KSh\n";
            $message .= "📅 Due Date: {$invoice->duedate}\n\n";
            $message .= "⚡ PAY NOW to avoid immediate service suspension!\n\n";
            $header = "🚨 Final Notice";
            break;
    }
    
    $message .= "🔗 Pay Now: {$websiteUrl}/whmcs/viewinvoice.php?id={$invoice->id}\n\n";
    $message .= "📞 Questions? Contact support: {$supportPhone}\n";
    $message .= "⚠️ This is an automated notification - please call for assistance";
    
    // Send WhatsApp message
    $data = [
        'phone' => $phoneNumber,
        'message' => $message,
        'header' => $header,
        'footer' => 'Powered by ' . $companyName
    ];
    
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
        logActivity("WhatsApp Overdue Notice Sent: {$phoneNumber} - Invoice #{$invoice->id} - Level {$urgencyLevel}");
    }
}

/**
 * Hook: Service Suspension Detection via Email
 */
add_hook('EmailSent', 1, function($vars) {
    // Check if this is a service suspension email
    if (strpos($vars['messagename'], 'Service Suspension') !== false || 
        strpos($vars['messagename'], 'Suspension Notification') !== false) {
        
        // Get client by email
        $client = \WHMCS\Database\Capsule::table('tblclients')
            ->where('email', $vars['email'])
            ->first();
        
        if ($client) {
            // Find recently suspended services
            $suspendedServices = \WHMCS\Database\Capsule::table('tblhosting')
                ->where('userid', $client->id)
                ->where('domainstatus', 'Suspended')
                ->where('updated_at', '>=', date('Y-m-d H:i:s', strtotime('-1 hour')))
                ->get();
            
            foreach ($suspendedServices as $service) {
                whatsapp_send_suspension_notice_by_service($service);
            }
        }
    }
});

/**
 * Send WhatsApp suspension notice by service
 */
function whatsapp_send_suspension_notice_by_service($service) {
    // Get addon settings
    $settings = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', 'whatsapp_notification')
        ->pluck('value', 'setting');
    
    if (($settings['enable_service_suspension'] ?? 'off') !== 'on') {
        return;
    }
    
    // Get client details
    $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $service->userid)->first();
    if (!$client || empty($client->phonenumber)) {
        return;
    }
    
    // Get API token
    $api_token = $settings['whatsapp_api_token'] ?? '';
    if (empty($api_token)) {
        return;
    }
    
    // Clean phone number
    $phoneNumber = preg_replace('/[^0-9+]/', '', $client->phonenumber);
    if (!str_starts_with($phoneNumber, '+')) {
        $phoneNumber = '+254' . ltrim($phoneNumber, '0');
    }
    
    // Get product name
    $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $service->productid)->first();
    $productName = $product ? $product->name : 'Service';
    
    // Get unpaid invoices
    $unpaidInvoices = \WHMCS\Database\Capsule::table('tblinvoices')
        ->where('userid', $client->id)
        ->where('status', 'Unpaid')
        ->orderBy('duedate', 'asc')
        ->get();
    
    // Get settings with fallbacks
    $supportPhone = $settings['support_phone_number'] ?? 'YOUR_SUPPORT_NUMBER';
    $websiteUrl = $settings['website_url'] ?? 'https://your-website.com';
    $companyName = $settings['company_name'] ?? 'Your Company';
    
    // Create suspension message
    $message = "Hi {$client->firstname}! ⚠️\n\n";
    $message .= "🚫 SERVICE SUSPENDED\n\n";
    $message .= "📦 Service: {$productName}\n";
    $message .= "🌐 Domain: {$service->domain}\n\n";
    $message .= "Your service has been suspended due to non-payment.\n\n";
    
    if ($unpaidInvoices->count() > 0) {
        $message .= "📋 UNPAID INVOICES:\n";
        foreach ($unpaidInvoices as $invoice) {
            $message .= "• Invoice #{$invoice->id}: {$invoice->total} KSh\n";
            $message .= "  Due: {$invoice->duedate}\n\n";
        }
    }
    
    $message .= "💡 TO REACTIVATE:\n";
    $message .= "1. Pay all outstanding invoices\n";
    $message .= "2. Service will be automatically reactivated\n\n";
    
    if ($unpaidInvoices->count() > 0) {
        $firstInvoice = $unpaidInvoices->first();
        $message .= "🔗 Pay Now: {$websiteUrl}/whmcs/viewinvoice.php?id={$firstInvoice->id}\n\n";
    }
    
    $message .= "📞 Need help? Contact support: {$supportPhone}\n";
    $message .= "⚠️ This is an automated notification - please call for assistance";
    
    // Send WhatsApp message
    $data = [
        'phone' => $phoneNumber,
        'message' => $message,
        'header' => '🚫 Service Suspended',
        'footer' => 'Powered by ' . $companyName
    ];
    
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
        logActivity("WhatsApp Service Suspension Sent: {$phoneNumber} - Service: {$productName}");
    }
}

// Keep your existing working hooks
add_hook('InvoiceCreation', 1, function($vars) {
    // Get addon settings
    $settings = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', 'whatsapp_notification')
        ->pluck('value', 'setting');
    
    if (($settings['enable_invoice_created'] ?? 'off') !== 'on') {
        return;
    }
    
    $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $vars['invoiceid'])->first();
    if (!$invoice) {
        return;
    }
    
    $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    if (!$client || empty($client->phonenumber)) {
        return;
    }
    
    // Get API token
    $api_token = $settings['whatsapp_api_token'] ?? '';
    if (empty($api_token)) {
        return;
    }
    
    // Clean phone number
    $phoneNumber = preg_replace('/[^0-9+]/', '', $client->phonenumber);
    if (!str_starts_with($phoneNumber, '+')) {
        $phoneNumber = '+254' . ltrim($phoneNumber, '0');
    }
    
    // Get invoice items for details
    $invoiceItems = \WHMCS\Database\Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoice->id)
        ->get();
    
    // Get settings with fallbacks
    $supportPhone = $settings['support_phone_number'] ?? 'YOUR_SUPPORT_NUMBER';
    $websiteUrl = $settings['website_url'] ?? 'https://your-website.com';
    $companyName = $settings['company_name'] ?? 'Your Company';
    
    // Create clean organized message with invoice details
    $message = "Hi {$client->firstname}! 📧\n\n";
    $message .= "📄 INVOICE #{$invoice->id}\n\n";
    
    // Invoice items
    $message .= "📋 SERVICES:\n";
    foreach ($invoiceItems as $item) {
        $message .= "• {$item->description}\n";
        $message .= "  Amount: {$item->amount} KSh\n\n";
    }
    
    $message .= "💰 TOTAL: {$invoice->total} KSh\n";
    $message .= "📅 DUE DATE: {$invoice->duedate}\n\n";
    
    $message .= "📧 Login with: {$client->email}\n\n";
    $message .= "🔗 Pay Now: {$websiteUrl}/whmcs/viewinvoice.php?id={$invoice->id}\n\n";
    $message .= "📞 Need help? Contact support: {$supportPhone}\n";
    $message .= "⚠️ This is an automated notification - please call for assistance";
    
    // Send WhatsApp message
    $data = [
        'phone' => $phoneNumber,
        'message' => $message,
        'header' => '📄 Invoice Ready',
        'footer' => 'Powered by ' . $companyName
    ];
    
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
        logActivity("WhatsApp Message Sent: {$phoneNumber} - 📄 Invoice Ready");
    }
});

add_hook('InvoicePaid', 1, function($vars) {
    // Get addon settings
    $settings = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', 'whatsapp_notification')
        ->pluck('value', 'setting');
    
    if (($settings['enable_invoice_paid'] ?? 'off') !== 'on') {
        return;
    }
    
    $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $vars['invoiceid'])->first();
    if (!$invoice) {
        return;
    }
    
    $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    if (!$client || empty($client->phonenumber)) {
        return;
    }
    
    $api_token = $settings['whatsapp_api_token'] ?? '';
    if (empty($api_token)) {
        return;
    }
    
    // Clean phone number
    $phoneNumber = preg_replace('/[^0-9+]/', '', $client->phonenumber);
    if (!str_starts_with($phoneNumber, '+')) {
        $phoneNumber = '+254' . ltrim($phoneNumber, '0');
    }
    
    // Get settings with fallbacks
    $supportPhone = $settings['support_phone_number'] ?? 'YOUR_SUPPORT_NUMBER';
    $companyName = $settings['company_name'] ?? 'Your Company';
    
    $message = "Thank you {$client->firstname}! 🎉\n\n";
    $message .= "✅ PAYMENT CONFIRMED\n\n";
    $message .= "📄 Invoice #{$invoice->id}\n";
    $message .= "💰 Amount: {$invoice->total} KSh\n\n";
    $message .= "Your services are now active!\n\n";
    $message .= "📞 Need help? Contact support: {$supportPhone}\n";
    $message .= "⚠️ This is an automated notification - please call for assistance\n\n";
    $message .= "We appreciate your business! ✨";
    
    // Send WhatsApp message
    $data = [
        'phone' => $phoneNumber,
        'message' => $message,
        'header' => '✅ Payment Confirmed',
        'footer' => 'Powered by ' . $companyName
    ];
    
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
        logActivity("WhatsApp Message Sent: {$phoneNumber} - ✅ Payment Confirmed");
    }
});

?>
