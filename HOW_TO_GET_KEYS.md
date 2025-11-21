# How to Get PVOutput Keys

This guide explains step by step how to get your **API Key** and **System ID** from PVOutput to configure the script.

## Step 1: Create PVOutput Account

1. Visit https://pvoutput.org/
2. Click **Register** in the top right corner
3. Fill out the registration form:
   - Email
   - Password
   - Name
   - Country
4. Confirm your email through the message sent

## Step 2: Add System

1. Log in to PVOutput
2. Go to **Settings** in the top menu
3. Click **Add System**
4. Fill in your system information:
   - **System Name**: Your system name (e.g., "My Solar Plant")
   - **Postcode**: ZIP code (optional)
   - **Timezone**: Time zone
   - **Peak Power**: Maximum system power in watts
   - Other optional information
5. Click **Save**

## Step 3: Get API Key and System ID

### Method 1: From Settings Page

1. Still on the **Settings** page
2. Scroll to the **API Settings** section
3. You will see:
   - **API Key**: A long string (e.g., `abc123def456ghi789jkl012mno345pqr678stu901vwx234yz`)
   - **System ID**: A number (e.g., `12345`)

### Method 2: From System Page

1. Go to **Systems** in the menu
2. Click on your system
3. On the system page, you will see:
   - **System ID**: Visible at the top of the page
   - To see the **API Key**, go to **Settings** → **API Settings**

## Step 4: Configure in Script

1. Copy the example file:
   ```bash
   cp config.php.example config.php
   ```

2. Edit the `config.php` file:
   ```php
   'pvoutput' => [
       'api_key' => 'YOUR_API_KEY_HERE',        // Paste your API Key here
       'system_id' => 'YOUR_SYSTEM_ID_HERE',    // Paste your System ID here
   ],
   ```

3. Replace the values:
   - `YOUR_API_KEY_HERE` → Your real API Key
   - `YOUR_SYSTEM_ID_HERE` → Your real System ID

## Configuration Example

```php
'pvoutput' => [
    'api_key' => 'abc123def456ghi789jkl012mno345pqr678stu901vwx234yz',
    'system_id' => '12345',
],
```

## Important: Security

⚠️ **NEVER** share your API Key publicly!

- The API Key gives full access to your PVOutput account
- Keep the `config.php` file secure
- The `config.php` file is in `.gitignore` and will not be committed
- If your API Key is exposed, generate a new one at **Settings** → **API Settings** → **Regenerate API Key**

## Testing Configuration

After configuring, test the script:

```bash
php solar.php
```

If everything is correct, you will see:
```
✓ Dados enviados com sucesso!
  Resposta: OK 200:Added
  HTTP Code: 200
```

## Free API Limits

Free PVOutput has some limits:

- **Requests**: Maximum of 60 requests per hour
- **Minimum interval**: 5 minutes between requests recommended
- **Data**: Up to 30 days of history

For higher limits, consider the paid plan.

## Troubleshooting

### "Invalid API Key"
- Check if you copied the complete API Key (no spaces)
- Confirm there are no extra quotes in config.php
- Try regenerating the API Key in PVOutput

### "Invalid System ID"
- Check if the System ID is correct
- Confirm the system exists in your account
- System ID is a number, no quotes needed

### "Rate limit exceeded"
- You exceeded the request limit
- Wait 1 hour or consider increasing the interval between executions

## Useful Links

- **PVOutput**: https://pvoutput.org/
- **API Documentation**: https://pvoutput.org/help.html#api
- **API Status**: https://pvoutput.org/status.jsp

