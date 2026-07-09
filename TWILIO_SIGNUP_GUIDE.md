# Twilio WhatsApp Setup - Step by Step Guide

**Time:** 10 minutes  
**Cost:** FREE ($15 trial credit)

---

## Step 1: Create Twilio Account

1. **Open:** https://www.twilio.com/try-twilio
2. **Fill Form:**
   - First Name: [Your Name]
   - Last Name: [Your Last Name]  
   - Email: zignlstechnology@gmail.com
   - Password: [Create a strong password]
3. **Verify Email:** Check inbox and click verification link
4. **Verify Phone:** Enter your phone number (+919403443775)
   - You'll get SMS code
   - Enter code to verify

---

## Step 2: Complete Setup Wizard

After signup, Twilio will show setup wizard:

1. **Which Twilio product?**
   - Select: **"Messaging"**
   - Click Next

2. **What do you plan to build?**
   - Select: **"Alerts & Notifications"**
   - Click Next

3. **How do you want to build?**
   - Select: **"With code"**
   - Choose: **"PHP"**
   - Click Next

4. **Would you like a phone number?**
   - Click: **"Skip for now"** (we'll use WhatsApp sandbox)

---

## Step 3: Get Account Credentials

1. **Go to Dashboard:** https://console.twilio.com/
2. **You'll see:**
   - **Account SID:** Starts with "AC..." (example: ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx)
   - **Auth Token:** Hidden, click "Show" to reveal

3. **Copy both:**
   - Account SID: `AC...`
   - Auth Token: `...`

**IMPORTANT:** Keep these safe! Ye credentials aapke `.env` file mein jayenge.

---

## Step 4: Access WhatsApp Sandbox

1. **Go to:** Console → Messaging → Try it out → Send a WhatsApp message
   - OR direct: https://console.twilio.com/us1/develop/sms/try-it-out/whatsapp-learn

2. **You'll see:**
   - Sandbox Number: **+1 415 523 8886**
   - Join Code: **join [something-like-abc]** (example: `join happy-tiger`)

3. **On Your Phone:**
   - Open WhatsApp
   - Add contact: +1 415 523 8886 (name it "Twilio Sandbox")
   - Send message: **join [your-code]** (exact code from console)
   - You'll get confirmation message!

**Screenshot Example:**
```
You: join happy-tiger
Twilio: Congratulations! You are now connected to the Twilio Sandbox for WhatsApp!
```

---

## Step 5: Test Additional Numbers (Optional)

For testing with other team members:
- They also need to send "join [code]" to +1 415 523 8886
- Each person gets sandbox access
- Good for testing multiple candidates

---

## Step 6: Get Your Credentials

**You need these 3 things:**

1. **Account SID:** `AC...` (from Dashboard)
2. **Auth Token:** `...` (from Dashboard - click Show)
3. **From Number:** `whatsapp:+14155238886` (sandbox number)

---

## Quick Checklist:

- [ ] Twilio account created
- [ ] Email verified
- [ ] Phone verified  
- [ ] Account SID copied
- [ ] Auth Token copied
- [ ] WhatsApp sandbox joined (sent "join" message)
- [ ] Got confirmation on WhatsApp

---

**Once done, provide:**
- Account SID
- Auth Token

**And I'll update the .env file!**

