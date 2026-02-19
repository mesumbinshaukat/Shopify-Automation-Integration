<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { width: 80%; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
        .header { background: #5c6ac4; color: white; padding: 10px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { padding: 20px; }
        .footer { text-align: center; font-size: 0.8em; color: #777; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Welcome to Our Store</h1>
        </div>
        <div class="content">
            <p>Hello {{ $customer->first_name }},</p>
            <p>Your account has been created. You can now login using the following credentials to see your exclusive pricing and discounts:</p>
            <p><strong>Email:</strong> {{ $customer->email }}</p>
            <p><strong>Password:</strong> {{ $password }}</p>
            <p>Once logged in, your discounts will be automatically applied at checkout.</p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Shopify World of Tech</p>
        </div>
    </div>
</body>
</html>
