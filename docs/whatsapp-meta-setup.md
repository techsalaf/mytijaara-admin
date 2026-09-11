# Meta WhatsApp Cloud API Setup Guide

Step-by-step setup instructions for configuring Meta Developer App, WhatsApp Business Account (WABA), and webhook subscriptions for MyTijaara.

---

## 1. Create Meta Developer App
1. Log into [developers.facebook.com](https://developers.facebook.com) using your Meta business login.
2. Click **My Apps** → **Create App**.
3. Select **Other** as the use case → Click **Next**.
4. Select **Business** as the app type → Click **Next**.
5. Set your app details:
   - **App Name:** `MyTijaara Vendor Concierge`
   - **App Contact Email:** your administrative email
   - **Business Account:** Select your verified Meta Business Portfolio.
6. Click **Create app**.

---

## 2. Add WhatsApp Product
1. On the App Dashboard, locate **WhatsApp** in the list of products.
2. Click **Set up**.
3. Under WhatsApp in the left sidebar, click **API Setup**.
4. Note the following values for your `.env`:
   - **Phone Number ID** → `WHATSAPP_PHONE_NUMBER_ID`
   - **WhatsApp Business Account ID** → `WHATSAPP_BUSINESS_ACCOUNT_ID`
   - Under App Settings → Basic:
     - **App ID** → `WHATSAPP_APP_ID`
     - **App Secret** → `WHATSAPP_APP_SECRET`

---

## 3. Generate Permanent System User Access Token
Do not use the temporary 24-hour test token in production.
1. Open [Meta Business Manager](https://business.facebook.com/settings).
2. In the left navigation, open **Users** → **System Users**.
3. Click **Add** to create a new system user:
   - **System Username:** `mytijaara-whatsapp-service`
   - **Role:** `Admin`
4. Click **Add Assets**:
   - Under **Apps**, assign your `MyTijaara Vendor Concierge` app with **Full Control**.
   - Under **WhatsApp Accounts**, assign your WABA with **Full Control**.
5. Click **Generate New Token**:
   - Select your app.
   - Set Token Expiration to **Never**.
   - Select permissions:
     - `whatsapp_business_messaging`
     - `whatsapp_business_management`
6. Copy the token and set it in `.env`:
   ```env
   WHATSAPP_ACCESS_TOKEN=EAAG...
   ```

---

## 4. Configure Webhook
1. Go to **WhatsApp** → **Configuration** in the Meta App Dashboard.
2. Click **Edit** next to Webhook:
   - **Callback URL:** `https://dashboard.mytijaara.com/webhooks/whatsapp` (or your ngrok URL for local testing)
   - **Verify Token:** A random secure 32+ character string matching `WHATSAPP_VERIFY_TOKEN` in `.env`.
3. Click **Verify and Save**. Meta will make a GET request with `hub.challenge`.
4. Once verified, click **Manage Webhook Subscriptions** and subscribe to:
   - `messages` (inbound vendor messages and delivery receipts)
   - `message_template_status_update` (template approvals/rejections)

---

## 5. Register and Verify Phone Number
1. Under **WhatsApp** → **API Setup**, under Step 5, click **Add Phone Number**.
2. Enter your company business display name and time zone.
3. Enter your phone number and verify via SMS or voice call OTP.
4. Set the verified Phone Number ID in your `.env`.

---

## 6. WhatsApp Business Verification & Live Mode
1. In the App Dashboard top header, toggle your app from **In Development** to **Live**.
2. Complete Meta Business Verification under **Security Center** in Meta Business Manager.
3. Review messaging tiers (Tier 1 starts at 1,000 business-initiated conversations per 24 hours; inbound vendor-initiated conversations are unlimited).
