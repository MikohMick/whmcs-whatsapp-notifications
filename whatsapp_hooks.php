<?php
/**
 * WHMCS WhatsApp Template Notifications - Complete Version
 * Uses WhatsApp Business API Templates for reliable delivery
 * Place in /includes/hooks/whatsapp_hooks.php
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Helper function to get client phone number
 */
function getClientPhoneNumber($client) {
    $phoneFields = ['phonenumber', 'phone', 'mobile'];
    
    foreach ($phoneFields as $field) {
        if (isset($client->$field) && !empty($client->$field)) {
            return $client->$field;
        }
    }
    
    return '';
}

/**
 * Helper function to clean phone number
 */
function cleanPhoneNumber($phoneNumber) {
    $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
    if (!str_starts_with($phoneNumber, '+')) {
        $phoneNumber = '+254' . ltrim($phoneNumber, '0');
    }
    return $phoneNumber;
}

/**
 * Helper function to get available templates from MessageMarvel
 */
function getAvailableTemplates($api_token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://messagemarvel.com/api/templates');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true);
    }
    
    return [];
}

/**
 * Helper function to send WhatsApp template message
 */
function sendWhatsAppTemplate($phoneNumber, $templateName, $parameters, $api_token) {
    $data = [
        'phone' => $phoneNumber,
        'template' => [
            'name' => $templateName,
            'language' => [
                'code' => 'en'
            ],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => $parameters
                ]
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://messagemarvel.com/api/send/template');
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
    
    return $httpCode === 200;
}

/**
 * Hook: Invoice Payment Reminder - For automatic reminders sent by cron
 */
add_hook('InvoicePaymentReminder', 1, function($vars) {
    // Get addon settings
    $settings = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', 'whatsapp_notification')
        ->pluck('value', 'setting');
    
    if (($settings['enable_invoice_due'] ?? 'off') !== 'on') {
        return;
    }
    
    // Send WhatsApp template for automatic payment reminder
    whatsapp_send_payment_reminder_template($vars['invoiceid'], 'automatic');
});

/**
 * Hook: Email Pre-Send - Catch manual payment reminders
 */
add_hook('EmailPreSend', 1, function($vars) {
    // Check if this is a payment reminder email
    if (in_array($vars['messagename'], ['Invoice Payment Reminder', 'Payment Reminder'])) {
        
        // Extract invoice ID from merge fields
        $invoiceId = null;
        if (isset($vars['mergefields']['invoice_id'])) {
            $invoiceId = $vars['mergefields']['invoice_id'];
        } elseif (isset($vars['mergefields']['invoiceid'])) {
            $invoiceId = $vars['mergefields']['invoiceid'];
        }
        
        if ($invoiceId) {
            whatsapp_send_payment_reminder_template($invoiceId, 'manual');
        }
    }
    
    // Also check for overdue notices
    if (in_array($vars['messagename'], ['First Overdue Invoice Notice', 'Second Overdue Invoice Notice', 'Third Overdue Invoice Notice'])) {
        
        // Extract invoice ID from merge fields
        $invoiceId = null;
        if (isset($vars['mergefields']['invoice_id'])) {
            $invoiceId = $vars['mergefields']['invoice_id'];
        } elseif (isset($vars['mergefields']['invoiceid'])) {
            $invoiceId = $vars['mergefields']['invoiceid'];
        }
        
        if ($invoiceId) {
            whatsapp_send_overdue_notice_template($invoiceId, $vars['messagename']);
        }
    }
});

/**
 * Hook: Email Sent - Catch overdue notices after they're sent
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
                whatsapp_send_overdue_notice_template($overdueInvoice->id, $vars['messagename']);
            }
        }
    }
});

/**
 * Send WhatsApp payment reminder template
 */
function whatsapp_send_payment_reminder_template($invoiceId, $type = 'unknown') {
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
    if (!$client) {
        return;
    }
    
    // Get phone number
    $phoneNumber = getClientPhoneNumber($client);
    if (empty($phoneNumber)) {
        return;
    }
    
    // Get API token
    $api_token = $settings['whatsapp_api_token'] ?? '';
    if (empty($api_token)) {
        return;
    }
    
    // Check if we already sent WhatsApp reminder for this invoice today
    $today = date('Y-m-d');
    $alreadySent = \WHMCS\Database\Capsule::table('tblactivitylog')
        ->where('description', 'like', "%WhatsApp Payment Reminder Sent%Invoice #{$invoiceId}%")
        ->where('date', '>=', $today . ' 00:00:00')
        ->exists();
    
    if ($alreadySent) {
        return;
    }
    
    // Clean phone number
    $phoneNumber = cleanPhoneNumber($phoneNumber);
    
    // Get settings with fallbacks
    $websiteUrl = $settings['website_url'] ?? 'https://your-website.com';
    
    // Prepare template parameters for payment_reminder_new template
    // Template variables: {{1}}=name, {{2}}=invoice_id, {{3}}=amount, {{4}}=due_date, {{5}}=email, {{6}}=payment_url
    $parameters = [
        [
            'type' => 'text',
            'text' => $client->firstname
        ],
        [
            'type' => 'text',
            'text' => $invoice->id
        ],
        [
            'type' => 'text',
            'text' => $invoice->total
        ],
        [
            'type' => 'text',
            'text' => $invoice->duedate
        ],
        [
            'type' => 'text',
            'text' => $client->email
        ],
        [
            'type' => 'text',
            'text' => $websiteUrl . '/whmcs/viewinvoice.php?id=' . $invoice->id
        ]
    ];
    
    // Send template message
    $result = sendWhatsAppTemplate($phoneNumber, 'payment_reminder_new', $parameters, $api_token);
    
    if ($result) {
        logActivity("WhatsApp Payment Reminder Sent: {$phoneNumber} - Invoice #{$invoice->id} ({$type})");
    }
}

/**
 * Send WhatsApp overdue notice template
 */
function whatsapp_send_overdue_notice_template($invoiceId, $emailType) {
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
    if (!$client) {
        return;
    }
    
    // Get phone number
    $phoneNumber = getClientPhoneNumber($client);
    if (empty($phoneNumber)) {
        return;
    }
    
    // Get API token
    $api_token = $settings['whatsapp_api_token'] ?? '';
    if (empty($api_token)) {
        return;
    }
    
    // Determine urgency level and template name
    $urgencyLevel = 1;
    $templateName = 'payment_overdue_1new';
    
    if (strpos($emailType, 'Second') !== false) {
        $urgencyLevel = 2;
        $templateName = 'payment_overdue_2new';
    } elseif (strpos($emailType, 'Third') !== false) {
        $urgencyLevel = 3;
        $templateName = 'payment_overdue_3new';
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
    $phoneNumber = cleanPhoneNumber($phoneNumber);
    
    // Calculate days overdue
    $dueDate = new DateTime($invoice->duedate);
    $today = new DateTime();
    $interval = $today->diff($dueDate);
    $daysOverdue = $interval->days;
    
    // Get settings with fallbacks
    $websiteUrl = $settings['website_url'] ?? 'https://your-website.com';
    
    // Prepare template parameters based on urgency level
    if ($urgencyLevel === 1) {
        // Level 1: {{1}}=name, {{2}}=invoice_id, {{3}}=days_overdue, {{4}}=amount, {{5}}=due_date, {{6}}=email, {{7}}=payment_url
        $parameters = [
            [
                'type' => 'text',
                'text' => $client->firstname
            ],
            [
                'type' => 'text',
                'text' => $invoice->id
            ],
            [
                'type' => 'text',
                'text' => $daysOverdue
            ],
            [
                'type' => 'text',
                'text' => $invoice->total
            ],
            [
                'type' => 'text',
                'text' => $invoice->duedate
            ],
            [
                'type' => 'text',
                'text' => $client->email
            ],
            [
                'type' => 'text',
                'text' => $websiteUrl . '/whmcs/viewinvoice.php?id=' . $invoice->id
            ]
        ];
    } else {
        // Level 2 & 3: {{1}}=name, {{2}}=invoice_id, {{3}}=days_overdue, {{4}}=amount, {{5}}=email, {{6}}=payment_url
        $parameters = [
            [
                'type' => 'text',
                'text' => $client->firstname
            ],
            [
                'type' => 'text',
                'text' => $invoice->id
            ],
            [
                'type' => 'text',
                'text' => $daysOverdue
            ],
            [
                'type' => 'text',
                'text' => $invoice->total
            ],
            [
                'type' => 'text',
                'text' => $client->email
            ],
            [
                'type' => 'text',
                'text' => $websiteUrl . '/whmcs/viewinvoice.php?id=' . $invoice->id
            ]
        ];
    }
    
    // Send template message
    $result = sendWhatsAppTemplate($phoneNumber, $templateName, $parameters, $api_token);
    
    if ($result) {
        logActivity("WhatsApp Overdue Notice Sent: {$phoneNumber} - Invoice #{$invoice->id} - Level {$urgencyLevel}");
    }
}

/**
 * Hook: Invoice Creation
 */
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
    if (!$client) {
        return;
    }
    
    $phoneNumber = getClientPhoneNumber($client);
    if (empty($phoneNumber)) {
        return;
    }
    
    // Get API token
    $api_token = $settings['whatsapp_api_token'] ?? '';
    if (empty($api_token)) {
        return;
    }
    
    $phoneNumber = cleanPhoneNumber($phoneNumber);
    
    // Get settings with fallbacks
    $websiteUrl = $settings['website_url'] ?? 'https://your-website.com';
    
    // Prepare template parameters for invoice_created_hosting template
    // Template variables: {{1}}=name, {{2}}=invoice_id, {{3}}=amount, {{4}}=due_date, {{5}}=email, {{6}}=payment_url
    $parameters = [
        [
            'type' => 'text',
            'text' => $client->firstname
        ],
        [
            'type' => 'text',
            'text' => $invoice->id
        ],
        [
            'type' => 'text',
            'text' => $invoice->total
        ],
        [
            'type' => 'text',
            'text' => $invoice->duedate
        ],
        [
            'type' => 'text',
            'text' => $client->email
        ],
        [
            'type' => 'text',
            'text' => $websiteUrl . '/whmcs/viewinvoice.php?id=' . $invoice->id
        ]
    ];
    
    // Send template message
    $result = sendWhatsAppTemplate($phoneNumber, 'invoice_created_hosting', $parameters, $api_token);
    
    if ($result) {
        logActivity("WhatsApp Message Sent: {$phoneNumber} - 📄 Invoice Ready");
    }
});

/**
 * Hook: Add Invoice Payment - Most reliable for automatic payments
 */
add_hook('AddInvoicePayment', 1, function($vars) {
    whatsapp_send_payment_confirmation($vars['invoiceid'], 'payment-applied');
});

/**
 * Hook: Invoice Paid - After payment processing and emails
 */
add_hook('InvoicePaid', 1, function($vars) {
    whatsapp_send_payment_confirmation($vars['invoiceid'], 'post-email');
});

/**
 * Hook: Invoice Paid Pre Email - Before payment emails are sent
 */
add_hook('InvoicePaidPreEmail', 1, function($vars) {
    whatsapp_send_payment_confirmation($vars['invoiceid'], 'pre-email');
});

/**
 * Send WhatsApp payment confirmation template
 */
function whatsapp_send_payment_confirmation($invoiceId, $type = 'unknown') {
    // Get addon settings
    $settings = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', 'whatsapp_notification')
        ->pluck('value', 'setting');
    
    if (($settings['enable_invoice_paid'] ?? 'off') !== 'on') {
        return;
    }
    
    $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        return;
    }
    
    $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    if (!$client) {
        return;
    }
    
    $phoneNumber = getClientPhoneNumber($client);
    if (empty($phoneNumber)) {
        return;
    }
    
    $api_token = $settings['whatsapp_api_token'] ?? '';
    if (empty($api_token)) {
        return;
    }
    
    // Check if we already sent WhatsApp confirmation for this invoice
    $alreadySent = \WHMCS\Database\Capsule::table('tblactivitylog')
        ->where('description', 'like', "%WhatsApp Message Sent%Payment Confirmed%")
        ->where('description', 'like', "%Invoice #{$invoiceId}%")
        ->exists();
    
    if ($alreadySent) {
        return;
    }
    
    $phoneNumber = cleanPhoneNumber($phoneNumber);
    
    // Prepare template parameters for payment_confirmed_new template
    // Template variables: {{1}}=name, {{2}}=invoice_id, {{3}}=amount, {{4}}=email
    $parameters = [
        [
            'type' => 'text',
            'text' => $client->firstname
        ],
        [
            'type' => 'text',
            'text' => $invoice->id
        ],
        [
            'type' => 'text',
            'text' => $invoice->total
        ],
        [
            'type' => 'text',
            'text' => $client->email
        ]
    ];
    
    // Send template message
    $result = sendWhatsAppTemplate($phoneNumber, 'payment_confirmed_new', $parameters, $api_token);
    
    if ($result) {
        logActivity("WhatsApp Message Sent: {$phoneNumber} - ✅ Payment Confirmed - Invoice #{$invoice->id} ({$type})");
    }
}

/**
 * Hook: Service Suspension Detection
 */
add_hook('AfterModuleSuspend', 1, function($vars) {
    // Get addon settings
    $settings = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', 'whatsapp_notification')
        ->pluck('value', 'setting');
    
    if (($settings['enable_service_suspension'] ?? 'off') !== 'on') {
        return;
    }
    
    $serviceId = $vars['serviceid'] ?? null;
    if (!$serviceId) {
        return;
    }
    
    $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
    if (!$service) {
        return;
    }
    
    $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $service->userid)->first();
    if (!$client) {
        return;
    }
    
    $phoneNumber = getClientPhoneNumber($client);
    if (empty($phoneNumber)) {
        return;
    }
    
    $api_token = $settings['whatsapp_api_token'] ?? '';
    if (empty($api_token)) {
        return;
    }
    
    $phoneNumber = cleanPhoneNumber($phoneNumber);
    
    // Get product name
    $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $service->productid)->first();
    $productName = $product ? $product->name : 'Service';
    
    // Get settings with fallbacks
    $websiteUrl = $settings['website_url'] ?? 'https://your-website.com';
    
    // Find unpaid invoice for payment link
    $unpaidInvoice = \WHMCS\Database\Capsule::table('tblinvoices')
        ->where('userid', $client->id)
        ->where('status', 'Unpaid')
        ->orderBy('duedate', 'asc')
        ->first();
    
    $paymentUrl = $unpaidInvoice ? 
        $websiteUrl . '/whmcs/viewinvoice.php?id=' . $unpaidInvoice->id : 
        $websiteUrl . '/whmcs/clientarea.php';
    
    // Prepare template parameters for service_suspendednew template
    // Template variables: {{1}}=name, {{2}}=service_name, {{3}}=domain, {{4}}=email, {{5}}=payment_url
    $parameters = [
        [
            'type' => 'text',
            'text' => $client->firstname
        ],
        [
            'type' => 'text',
            'text' => $productName
        ],
        [
            'type' => 'text',
            'text' => $service->domain
        ],
        [
            'type' => 'text',
            'text' => $client->email
        ],
        [
            'type' => 'text',
            'text' => $paymentUrl
        ]
    ];
    
    // Send template message
    $result = sendWhatsAppTemplate($phoneNumber, 'service_suspendednew', $parameters, $api_token);
    
    if ($result) {
        logActivity("WhatsApp Service Suspension Sent: {$phoneNumber} - Service: {$productName}");
    }
});

/**
 * Hook: Service Reactivation
 */
add_hook('AfterModuleUnsuspend', 1, function($vars) {
    // Get addon settings
    $settings = \WHMCS\Database\Capsule::table('tbladdonmodules')
        ->where('module', 'whatsapp_notification')
        ->pluck('value', 'setting');
    
    if (($settings['enable_service_activation'] ?? 'off') !== 'on') {
        return;
    }
    
    $serviceId = $vars['serviceid'] ?? null;
    if (!$serviceId) {
        return;
    }
    
    $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
    if (!$service) {
        return;
    }
    
    $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $service->userid)->first();
    if (!$client) {
        return;
    }
    
    $phoneNumber = getClientPhoneNumber($client);
    if (empty($phoneNumber)) {
        return;
    }
    
    $api_token = $settings['whatsapp_api_token'] ?? '';
    if (empty($api_token)) {
        return;
    }
    
    $phoneNumber = cleanPhoneNumber($phoneNumber);
    
    // Get product name
    $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $service->productid)->first();
    $productName = $product ? $product->name : 'Service';
    
    // Prepare template parameters for service_reactivatednew template
    // Template variables: {{1}}=name, {{2}}=service_name, {{3}}=domain, {{4}}=email
    $parameters = [
        [
            'type' => 'text',
            'text' => $client->firstname
        ],
        [
            'type' => 'text',
            'text' => $productName
        ],
        [
            'type' => 'text',
            'text' => $service->domain
        ],
        [
            'type' => 'text',
            'text' => $client->email
        ]
    ];
    
    // Send template message
    $result = sendWhatsAppTemplate($phoneNumber, 'service_reactivatednew', $parameters, $api_token);
    
    if ($result) {
        logActivity("WhatsApp Service Reactivation Sent: {$phoneNumber} - Service: {$productName}");
    }
});

?>
