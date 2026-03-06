<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { width: 90%; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
        .header { background: #5c6ac4; color: white; padding: 10px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { padding: 20px; }
        .footer { text-align: center; font-size: 0.8em; color: #777; margin-top: 20px; }
        .field-label { font-weight: bold; color: #555; }
        .field-value { margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>New Access Request Received</h1>
        </div>
        <div class="content">
            <p>A new access request has been submitted with the following details:</p>
            
            <div class="field-value"><span class="field-label">Practice Name:</span> {{ $data['practice_name'] }}</div>
            <div class="field-value"><span class="field-label">Name:</span> {{ $data['first_name'] }} {{ $data['last_name'] }}</div>
            <div class="field-value"><span class="field-label">Title:</span> {{ $data['title'] }}</div>
            <div class="field-value"><span class="field-label">Email:</span> {{ $data['email'] }}</div>
            <div class="field-value"><span class="field-label">Phone:</span> {{ $data['phone'] }}</div>
            <div class="field-value"><span class="field-label">City:</span> {{ $data['city'] }}</div>
            <div class="field-value"><span class="field-label">State:</span> {{ $data['state'] }}</div>
            <div class="field-value"><span class="field-label">Specialty:</span> {{ $data['specialty'] }}</div>
            <div class="field-value"><span class="field-label">Interests:</span> {{ is_array($data['interests']) ? implode(', ', $data['interests']) : $data['interests'] }}</div>
            <div class="field-value"><span class="field-label">Message:</span><br>{{ nl2br(e($data['message'])) }}</div>
        </div>
        <div class="footer">
            <p>Sent from your Shopify Custom App</p>
        </div>
    </div>
</body>
</html>
