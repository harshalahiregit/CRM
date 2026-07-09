# 🔐 HR Module Login माहिती (मराठी)

## 🎯 लॉगिन कसं करायचं?

### **महत्वपूर्ण**: Login करताना **पहिले Role निवडा**, मग Email आणि Password टाका!

---

## 👥 उपलब्ध Users

### 1️⃣ **Admin (सर्व काम करू शकतो)**
```
Role:     🛡️ Admin
Email:    admin@demo.com
Password: password123
```

**Admin काय करू शकतो:**
- ✅ सगळे manpower requests बघू शकतो
- ✅ Job postings create करू शकतो
- ✅ Candidates manage करू शकतो
- ✅ Interviews schedule करू शकतो
- ✅ Offers generate करू शकतो
- ✅ Employee records access करू शकतो
- ✅ पूर्ण system चा access

---

### 2️⃣ **HR Executive (HR चे काम)**
```
Role:     👔 HR Executive
Email:    hr@demo.com
Password: password123
```

**HR Executive काय करू शकतो:**
- ✅ Candidates manage करू शकतो
- ✅ Interviews schedule करू शकतो
- ✅ Job postings create करू शकतो
- ✅ Onboarding handle करू शकतो
- ⚠️ Manpower requests approve करू **शकत नाही** (फक्त manager करतो)

---

### 3️⃣ **Hiring Manager (Department Manager)**
```
Role:     📋 Hiring Manager
Email:    manager@demo.com
Password: password123
```

**Hiring Manager काय करू शकतो:**
- ✅ फक्त त्याला assign केलेल्या requests बघू शकतो
- ✅ Manpower requests approve/reject करू शकतो
- ✅ त्याच्या positions साठी candidates बघू शकतो
- ⚠️ Job postings create करू **शकत नाही**

---

## 🚀 Login Process (Step-by-Step)

### **1. Browser उघडा**
```
URL: http://localhost:5173
```

### **2. पहिले Role निवडा (Dropdown मधून)**
Login page वर सर्वात वर **Role Dropdown** दिसेल:

**Admin साठी:**
- Dropdown उघडा → **🛡️ Admin** select करा

**HR साठी:**
- Dropdown उघडा → **👔 HR Executive** select करा

**Manager साठी:**
- Dropdown उघडा → **📋 Hiring Manager** select करा

### **3. Email टाका**
```
Admin साठी:    admin@demo.com
HR साठी:        hr@demo.com
Manager साठी:   manager@demo.com
```

### **4. Password टाका**
```
सगळ्यांचा password: password123
```

### **5. LOGIN बटन दाबा**
```
✅ Login successful झाल्यावर Dashboard वर redirect होईल
```

---

## ⚠️ सामान्य चुका (Common Mistakes)

### ❌ **चूक 1: Role निवडला नाही**
```
Error: "Please select a role"
Solution: पहिले dropdown मधून role निवडा!
```

### ❌ **चूक 2: चुकीचा Role निवडला**
```
Error: "Invalid credentials"
Solution: 
- admin@demo.com साठी "Admin" role निवडा
- hr@demo.com साठी "HR Executive" role निवडा
- manager@demo.com साठी "Hiring Manager" role निवडा
```

### ❌ **चूक 3: Email आणि Role match होत नाहीत**
```
Email: admin@demo.com
Role: HR Executive ❌ WRONG!

Email: admin@demo.com  
Role: Admin ✅ CORRECT!
```

---

## 📊 System मध्ये काय आहे?

### Data Available:
- **Job Postings**: 12 (7 active)
- **Candidates**: 24 (19 active)
- **Open Positions**: 14
- **Interview Rounds**: Multiple scheduled

---

## 🎯 Test करायला Try करा

### **Test 1: Admin म्हणून Login**
```
1. Role: Admin निवडा
2. Email: admin@demo.com
3. Password: password123
4. Dashboard मध्ये सगळे data दिसेल
```

### **Test 2: HR Executive म्हणून Login**
```
1. Role: HR Executive निवडा
2. Email: hr@demo.com
3. Password: password123
4. Candidates manage करू शकाल
```

### **Test 3: Hiring Manager म्हणून Login**
```
1. Role: Hiring Manager निवडा
2. Email: manager@demo.com
3. Password: password123
4. फक्त assigned requests दिसतील
```

---

## 📱 What's Next?

### Email Notifications Enable करायची असल्यास:
1. Gmail App Password generate करा
2. `.env` file मध्ये update करा
3. Test command: `php artisan test:emails`

### WhatsApp Notifications Enable करायची असल्यास:
1. Twilio account create करा
2. Account SID आणि Auth Token घ्या
3. `.env` file मध्ये update करा
4. Test command: `php artisan test:whatsapp --phone=+919403443775`

---

## 💡 Remember!

### Login करताना हा sequence follow करा:
```
Step 1: Role dropdown उघडा आणि role निवडा ⬅️ सर्वात महत्वाचं!
Step 2: Email टाका
Step 3: Password टाका
Step 4: LOGIN button दाबा
```

### Role आणि Email match करा:
```
✅ admin@demo.com → Admin role
✅ hr@demo.com → HR Executive role  
✅ manager@demo.com → Hiring Manager role
```

---

**System Status**: ✅ चालू आहे
**Backend**: http://127.0.0.1:8000
**Frontend**: http://localhost:5173

**समजलं का? आता login करून बघा! 🚀**
