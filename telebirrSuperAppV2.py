"""
Telebirr Desktop Simulator - Complete Working Version
- Screen: 390x650
- PHP Backend integration
- Proper PIN handling
- Payment receipt display
"""

import tkinter as tk
from tkinter import messagebox
import random
import string
import datetime
import json
import os
import requests

# ─── COLORS ──────────────────────────────────────────────────────────────────
C_GREEN       = "#5cb85c"
C_GREEN_DARK  = "#4a9a4a"
C_GREEN_HDR   = "#6abf6a"
C_BLUE        = "#1565c0"
C_ORANGE      = "#ff9800"
C_WHITE       = "#ffffff"
C_BG          = "#f2f2f2"
C_TEXT        = "#111111"
C_MUTED       = "#666666"
C_BORDER      = "#e0e0e0"
C_RED         = "#e53935"
C_LIGHT_GREEN = "#e8f5e9"

# ─── FONT SIZES ───────────────────────────────────────────────────────────────
F_SMALL  = 9
F_NORMAL = 11
F_MEDIUM = 13
F_LARGE  = 16
F_TITLE  = 20

# ─── PHP BACKEND API URL ─────────────────────────────────────────────────────
PHP_API_URL = "http://localhost/broker_system/api"


# ══════════════════════════════════════════════════════════════════════════════
#  LOCAL STORAGE
# ══════════════════════════════════════════════════════════════════════════════
class LocalStorage:
    def __init__(self, filename="telebirr_users.json"):
        self.filename = filename
        self.data = self.load()
    
    def load(self):
        if os.path.exists(self.filename):
            try:
                with open(self.filename, 'r') as f:
                    return json.load(f)
            except:
                pass
        return {"users": {}, "transactions": []}
    
    def save(self):
        with open(self.filename, 'w') as f:
            json.dump(self.data, f, indent=2)
    
    def create_user(self, phone, full_name, pin, initial_balance=1000.0):
        if phone in self.data["users"]:
            return False, "User already exists"
        
        self.data["users"][phone] = {
            "id": len(self.data["users"]) + 1,
            "phone": phone,
            "full_name": full_name,
            "pin": pin,
            "balance": float(initial_balance),
            "level": 1,
            "endekise": 0.0,
            "rewards": 0.0,
            "created_at": datetime.datetime.now().isoformat()
        }
        self.save()
        return True, "Account created successfully!"
    
    def get_user(self, phone):
        return self.data["users"].get(phone)
    
    def verify_pin(self, phone, pin):
        user = self.get_user(phone)
        if user and user["pin"] == pin:
            return True, user
        return False, None
    
    def update_balance(self, phone, amount, operation="add"):
        user = self.get_user(phone)
        if not user:
            return False
        
        amount = float(amount)
        
        if operation == "add":
            user["balance"] += amount
        elif operation == "subtract":
            if user["balance"] < amount:
                return False
            user["balance"] -= amount
        elif operation == "set":
            user["balance"] = amount
        
        self.save()
        return True
    
    def add_transaction(self, user_phone, tx_type, amount, fee, desc, ref=None):
        if not ref:
            ref = "TXN" + "".join(random.choices(string.digits, k=12))
        
        self.data["transactions"].insert(0, {
            "user_phone": user_phone,
            "type": tx_type,
            "amount": float(amount),
            "fee": float(fee),
            "description": desc,
            "reference": ref,
            "date": datetime.datetime.now().strftime("%d %b %Y %H:%M"),
            "status": "completed"
        })
        self.save()
        return ref
    
    def get_transactions(self, phone, limit=20):
        """Get recent transactions for a user"""
        return [t for t in self.data["transactions"] if t["user_phone"] == phone][:limit]


# ══════════════════════════════════════════════════════════════════════════════
#  DEMO USERS
# ══════════════════════════════════════════════════════════════════════════════
DEMO_USERS = {
    "+251912345678": {"id": 1, "full_name": "Abebe Kebede",  "balance": 50000.0,  "pin": "1234", "level": 3, "endekise": 2500.0, "rewards": 1200.0},
    "+251923456789": {"id": 2, "full_name": "Tigist Haile",  "balance": 25000.0,  "pin": "1234", "level": 2, "endekise": 1000.0, "rewards": 500.0},
    "+251934567890": {"id": 3, "full_name": "Dawit Tadesse", "balance": 100000.0, "pin": "1234", "level": 4, "endekise": 5000.0, "rewards": 2500.0},
    "+251992116527": {"id": 5, "full_name": "Mesfin",        "balance": 32500.0,  "pin": "1234", "level": 3, "endekise": 1500.0, "rewards": 800.0},
    "+251988400501": {"id": 6, "full_name": "Test User",     "balance": 100000.0, "pin": "1234", "level": 2, "endekise": 0.0, "rewards": 0.0},
}


# ══════════════════════════════════════════════════════════════════════════════
#  WIDGET HELPERS
# ══════════════════════════════════════════════════════════════════════════════
def make_frame(parent, bg=C_WHITE, **kwargs):
    return tk.Frame(parent, bg=bg, **kwargs)

def make_label(parent, text, size=F_NORMAL, bold=False, color=C_TEXT, bg=C_WHITE, **kwargs):
    weight = "bold" if bold else "normal"
    return tk.Label(parent, text=text, font=("Segoe UI", size, weight),
                    fg=color, bg=bg, **kwargs)

def make_button(parent, text, cmd, bg=C_GREEN, fg=C_WHITE, size=F_NORMAL,
                pad_x=20, pad_y=8, **kwargs):
    btn = tk.Button(parent, text=text, command=cmd,
                    bg=bg, fg=fg, font=("Segoe UI", size, "bold"),
                    relief="flat", bd=0, cursor="hand2",
                    activebackground=C_GREEN_DARK, activeforeground=C_WHITE,
                    padx=pad_x, pady=pad_y, **kwargs)
    return btn

def separator(parent, bg=C_BORDER, height=1):
    return tk.Frame(parent, bg=bg, height=height)


# ══════════════════════════════════════════════════════════════════════════════
#  MAIN APPLICATION
# ══════════════════════════════════════════════════════════════════════════════
class TelebirrApp(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("telebirr SuperApp")
        self.geometry("390x650")
        self.resizable(False, False)
        self.configure(bg=C_BG)
        self.protocol("WM_DELETE_WINDOW", self.on_close)

        self.storage = LocalStorage()
        self.current_user = None
        self.balance_visible = False
        self.current_tab = "home"
        self.session = requests.Session()

        self.container = make_frame(self, bg=C_BG)
        self.container.pack(fill="both", expand=True)

        self.screens = {}
        self._build_login()
        self._build_create_account()
        self._build_main()

        self.show_screen("login")

    # ─────────────────────────────────────────────────────────────────────────
    #  API HELPER
    # ─────────────────────────────────────────────────────────────────────────
    def call_php_api(self, endpoint, data=None):
        try:
            url = f"{PHP_API_URL}/{endpoint}"
            print(f"Calling API: {url}")
            if data:
                print(f"Data: {data}")
            
            if data:
                response = self.session.post(url, json=data, timeout=10, 
                                            headers={'Content-Type': 'application/json'})
            else:
                response = self.session.get(url, timeout=10)
            
            print(f"Response status: {response.status_code}")
            print(f"Response text: {response.text[:200]}")
            
            if response.status_code == 200:
                if response.text and response.text.strip():
                    return response.json()
                else:
                    return {"success": False, "error": "Empty response from server"}
            else:
                return {"success": False, "error": f"HTTP {response.status_code}"}
                
        except requests.exceptions.ConnectionError:
            return {"success": False, "error": "Cannot connect to server. Make sure XAMPP is running."}
        except Exception as e:
            return {"success": False, "error": str(e)}

    # ─────────────────────────────────────────────────────────────────────────
    #  SCREEN SWITCHER
    # ─────────────────────────────────────────────────────────────────────────
    def show_screen(self, name):
        for s in self.screens.values():
            s.pack_forget()
        if name in self.screens:
            self.screens[name].pack(fill="both", expand=True)

    # ─────────────────────────────────────────────────────────────────────────
    #  LOGIN SCREEN
    # ─────────────────────────────────────────────────────────────────────────
    def _build_login(self):
        root = make_frame(self.container, bg="#f5f5e8")
        self.screens["login"] = root

        topbar = make_frame(root, bg=C_WHITE)
        topbar.pack(fill="x")
        make_label(topbar, "ethio telecom", F_SMALL, bg=C_WHITE, color="#555").pack(side="left", padx=12, pady=8)
        make_label(topbar, "telebirr", F_SMALL, bold=True, bg=C_WHITE, color="#00bcd4").pack(side="right", padx=12, pady=8)
        separator(root, bg=C_BORDER).pack(fill="x")

        body = make_frame(root, bg="#f5f5e8")
        body.pack(fill="both", expand=True, padx=28, pady=10)

        lang_row = make_frame(body, bg="#f5f5e8")
        lang_row.pack(fill="x", pady=(6, 0))
        make_label(lang_row, "English ▾", F_SMALL, bg="#f5f5e8", color=C_TEXT).pack(side="right")

        make_label(body, "telebirr SuperApp!", F_LARGE, bold=True, bg="#f5f5e8", color="#1a237e").pack(anchor="w", pady=(10, 0))
        make_label(body, "All-in-One", F_MEDIUM, bg="#f5f5e8", color="#1a237e").pack(anchor="w")
        make_label(body, "Login", F_TITLE, bold=True, bg="#f5f5e8", color=C_TEXT).pack(anchor="w", pady=(4, 0))
        
        underline = make_frame(body, bg=C_GREEN, height=3, width=60)
        underline.pack(anchor="w", pady=(2, 16))

        make_label(body, "Mobile Number", F_SMALL, bg="#f5f5e8", color=C_MUTED).pack(anchor="w")
        phone_frame = make_frame(body, bg="#eaeadc")
        phone_frame.pack(fill="x", pady=(4, 20), ipady=6)
        make_label(phone_frame, "+251", F_MEDIUM, bg="#eaeadc", color=C_MUTED).pack(side="left", padx=(12, 6), pady=8)

        self.phone_var = tk.StringVar(value="988400501")
        phone_entry = tk.Entry(phone_frame, textvariable=self.phone_var,
                               font=("Segoe UI", F_MEDIUM, "bold"),
                               bg="#eaeadc", fg=C_TEXT, relief="flat", bd=0)
        phone_entry.pack(side="left", fill="x", expand=True, padx=(0, 12), pady=8)
        phone_entry.bind("<Return>", lambda e: self._do_login())

        make_button(body, "Next", self._do_login, bg="#1a237e", size=F_MEDIUM, pad_y=12).pack(fill="x", pady=(0, 14))

        link_row = make_frame(body, bg="#f5f5e8")
        link_row.pack()
        make_label(link_row, "Don't have an account? ", F_SMALL, bg="#f5f5e8", color=C_MUTED).pack(side="left")
        create_link = make_label(link_row, "Create New Account", F_SMALL, bold=True, bg="#f5f5e8", color=C_GREEN_DARK)
        create_link.pack(side="left")
        create_link.bind("<Button-1>", lambda e: self.show_screen("create_account"))
        create_link.configure(cursor="hand2")

        help_row = make_frame(body, bg="#f5f5e8")
        help_row.pack(pady=10)
        make_label(help_row, "teleHub", F_SMALL, bold=True, bg="#f5f5e8", color=C_GREEN_DARK).pack(side="left", padx=20)
        make_label(help_row, "Help", F_SMALL, bold=True, bg="#f5f5e8", color=C_GREEN_DARK).pack(side="left", padx=20)

        footer = make_frame(body, bg="#f5f5e8")
        footer.pack(side="bottom", pady=12)
        make_label(footer, "Terms and Conditions", F_SMALL, bg="#f5f5e8", color=C_GREEN_DARK).pack()
        make_label(footer, "@2026 Ethio telecom. All rights reserved 1.2.9 version",
                   F_SMALL - 1, bg="#f5f5e8", color=C_MUTED).pack()

    def _build_create_account(self):
        root = make_frame(self.container, bg="#f5f5e8")
        self.screens["create_account"] = root

        topbar = make_frame(root, bg=C_WHITE)
        topbar.pack(fill="x")
        back_btn = make_label(topbar, "← Back", F_SMALL, bold=True, bg=C_WHITE, color=C_GREEN)
        back_btn.pack(side="left", padx=12, pady=8)
        back_btn.bind("<Button-1>", lambda e: self.show_screen("login"))
        back_btn.configure(cursor="hand2")
        make_label(topbar, "Create Account", F_SMALL, bold=True, bg=C_WHITE, color=C_TEXT).pack(side="left", padx=20, pady=8)
        separator(root, bg=C_BORDER).pack(fill="x")

        body = make_frame(root, bg="#f5f5e8")
        body.pack(fill="both", expand=True, padx=28, pady=10)

        make_label(body, "Create New Account", F_LARGE, bold=True, bg="#f5f5e8", color="#1a237e").pack(anchor="w", pady=(10, 20))

        make_label(body, "Full Name", F_SMALL, bg="#f5f5e8", color=C_MUTED).pack(anchor="w")
        self.reg_name_var = tk.StringVar()
        name_entry = tk.Entry(body, textvariable=self.reg_name_var,
                              font=("Segoe UI", F_MEDIUM),
                              relief="solid", bd=1, highlightcolor=C_GREEN)
        name_entry.pack(fill="x", pady=(4, 12), ipady=8)

        make_label(body, "Mobile Number", F_SMALL, bg="#f5f5e8", color=C_MUTED).pack(anchor="w")
        phone_frame = make_frame(body, bg="#eaeadc", relief="solid", bd=1)
        phone_frame.pack(fill="x", pady=(4, 12), ipady=6)
        make_label(phone_frame, "+251", F_MEDIUM, bg="#eaeadc", color=C_MUTED).pack(side="left", padx=(12, 6), pady=8)
        self.reg_phone_var = tk.StringVar()
        tk.Entry(phone_frame, textvariable=self.reg_phone_var,
                font=("Segoe UI", F_MEDIUM, "bold"),
                bg="#eaeadc", fg=C_TEXT, relief="flat", bd=0).pack(side="left", fill="x", expand=True, padx=(0, 12), pady=8)

        make_label(body, "Create 4-digit PIN", F_SMALL, bg="#f5f5e8", color=C_MUTED).pack(anchor="w")
        self.reg_pin_var = tk.StringVar()
        pin_entry = tk.Entry(body, textvariable=self.reg_pin_var,
                            show="●", font=("Segoe UI", F_MEDIUM),
                            relief="solid", bd=1, highlightcolor=C_GREEN)
        pin_entry.pack(fill="x", pady=(4, 6), ipady=8)

        make_label(body, "Confirm PIN", F_SMALL, bg="#f5f5e8", color=C_MUTED).pack(anchor="w")
        self.reg_confirm_pin_var = tk.StringVar()
        confirm_entry = tk.Entry(body, textvariable=self.reg_confirm_pin_var,
                                show="●", font=("Segoe UI", F_MEDIUM),
                                relief="solid", bd=1, highlightcolor=C_GREEN)
        confirm_entry.pack(fill="x", pady=(4, 20), ipady=8)

        make_button(body, "Create Account", self._create_account,
                   bg="#1a237e", size=F_MEDIUM, pad_y=12).pack(fill="x")

        make_label(body, "Initial balance: 1,000 ETB", F_SMALL, bg="#f5f5e8", color=C_MUTED).pack(pady=(10, 0))
        make_label(body, "You can add more balance anytime", F_SMALL - 1, bg="#f5f5e8", color=C_MUTED).pack()

    def _create_account(self):
        name = self.reg_name_var.get().strip()
        phone_raw = self.reg_phone_var.get().strip().replace(" ", "")
        phone = "+251" + phone_raw if not phone_raw.startswith("+251") else phone_raw
        pin = self.reg_pin_var.get().strip()
        confirm = self.reg_confirm_pin_var.get().strip()

        if not name:
            messagebox.showerror("Error", "Please enter your full name")
            return
        if len(phone_raw) < 8:
            messagebox.showerror("Error", "Please enter a valid phone number")
            return
        if len(pin) != 4 or not pin.isdigit():
            messagebox.showerror("Error", "PIN must be 4 digits")
            return
        if pin != confirm:
            messagebox.showerror("Error", "PINs do not match")
            return

        success, message = self.storage.create_user(phone, name, pin)
        
        if not success:
            if phone in DEMO_USERS:
                messagebox.showerror("Error", "This phone number is already registered")
                return
        
        DEMO_USERS[phone] = {
            "id": len(DEMO_USERS) + 1,
            "full_name": name,
            "balance": 1000.0,
            "pin": pin,
            "level": 1,
            "endekise": 0.0,
            "rewards": 100.0
        }

        messagebox.showinfo("Success", f"Account created successfully!\n\nWelcome, {name}!\nYour balance: 1,000 ETB")
        
        self.phone_var.set(phone_raw)
        self.show_screen("login")
        self._do_login()

    def _do_login(self):
        raw = self.phone_var.get().strip().replace(" ", "")
        phone = "+251" + raw if not raw.startswith("+251") else raw

        user = self.storage.get_user(phone)
        if not user:
            user = DEMO_USERS.get(phone)

        if not user:
            messagebox.showerror("Error", "Account not found. Please create an account first.")
            return

        self._show_pin_dialog("Login", 0, lambda pin: self._verify_login(phone, pin, user))

    def _verify_login(self, phone, entered_pin, user):
        if user["pin"] != entered_pin:
            messagebox.showerror("Error", "Invalid PIN")
            return
        
        self.current_user = {
            "id": user.get("id", 0),
            "full_name": user["full_name"],
            "phone": phone,
            "balance": float(user["balance"]),
            "pin": user["pin"],
            "level": user.get("level", 1),
            "endekise": user.get("endekise", 0),
            "rewards": user.get("rewards", 0),
        }

        self._refresh_home()
        self.show_screen("main")
        self._switch_tab("home")

    # ─────────────────────────────────────────────────────────────────────────
    #  MAIN SCREEN
    # ─────────────────────────────────────────────────────────────────────────
    def _build_main(self):
        root = make_frame(self.container, bg=C_BG)
        self.screens["main"] = root

        self.tab_container = make_frame(root, bg=C_BG)
        self.tab_container.pack(fill="both", expand=True)

        self._build_bottom_nav(root)

        self.tabs = {}
        self._build_home_tab()
        self._build_payment_tab()
        self._build_apps_tab()
        self._build_engage_tab()
        self._build_account_tab()

    def _switch_tab(self, name):
        for t in self.tabs.values():
            t.pack_forget()
        self.tabs[name].pack(fill="both", expand=True)
        self.current_tab = name
        self._update_nav_highlight(name)

    def _build_bottom_nav(self, parent):
        nav = make_frame(parent, bg=C_WHITE, height=60)
        nav.pack(fill="x", side="bottom")
        nav.pack_propagate(False)
        separator(nav, bg=C_BORDER).pack(fill="x", side="top")

        self.nav_buttons = {}
        tabs = [
            ("home",    "🏠", "Home"),
            ("payment", "💳", "Payment"),
            ("apps",    "📱", "Apps"),
            ("engage",  "💬", "Engage"),
            ("account", "👤", "Account"),
        ]
        for key, icon, label in tabs:
            frame = make_frame(nav, bg=C_WHITE)
            frame.pack(side="left", expand=True, fill="both")
            frame.bind("<Button-1>", lambda e, k=key: self._switch_tab(k))
            frame.configure(cursor="hand2")

            icon_lbl = make_label(frame, icon, F_MEDIUM, bg=C_WHITE, color=C_MUTED)
            icon_lbl.pack(pady=(6, 0))
            icon_lbl.bind("<Button-1>", lambda e, k=key: self._switch_tab(k))

            txt_lbl = make_label(frame, label, F_SMALL - 1, bg=C_WHITE, color=C_MUTED)
            txt_lbl.pack()
            txt_lbl.bind("<Button-1>", lambda e, k=key: self._switch_tab(k))

            self.nav_buttons[key] = {"frame": frame, "icon": icon_lbl, "label": txt_lbl}

    def _update_nav_highlight(self, active):
        for key, widgets in self.nav_buttons.items():
            if key == active:
                widgets["label"].config(fg=C_GREEN, font=("Segoe UI", F_SMALL - 1, "bold"))
            else:
                widgets["label"].config(fg=C_MUTED, font=("Segoe UI", F_SMALL - 1, "normal"))

    # ─────────────────────────────────────────────────────────────────────────
    #  HOME TAB
    # ─────────────────────────────────────────────────────────────────────────
    def _build_home_tab(self):
        root = make_frame(self.tab_container, bg=C_BG)
        self.tabs["home"] = root

        hdr = make_frame(root, bg=C_GREEN_HDR)
        hdr.pack(fill="x")

        logo_row = make_frame(hdr, bg=C_GREEN_HDR)
        logo_row.pack(fill="x", padx=12, pady=(8, 4))
        make_label(logo_row, "ethio telecom", F_SMALL, bg=C_GREEN_HDR, color=C_WHITE).pack(side="left")
        make_label(logo_row, "⊕ telebirr", F_SMALL, bold=True, bg=C_GREEN_HDR, color=C_WHITE).pack(side="right")

        greet_row = make_frame(hdr, bg=C_GREEN_HDR)
        greet_row.pack(fill="x", padx=12, pady=4)
        self.home_greet = make_label(greet_row, "Selam, User", F_MEDIUM, bold=True, bg=C_GREEN_HDR, color=C_WHITE)
        self.home_greet.pack(side="left")
        icons_row = make_frame(greet_row, bg=C_GREEN_HDR)
        icons_row.pack(side="right")
        make_label(icons_row, "🔍  🔔  Eng ▾", F_SMALL, bg=C_GREEN_HDR, color=C_WHITE).pack()

        bal_frame = make_frame(hdr, bg=C_GREEN_HDR)
        bal_frame.pack(fill="x", padx=12, pady=(4, 10))

        bal_lbl_row = make_frame(bal_frame, bg=C_GREEN_HDR)
        bal_lbl_row.pack()
        make_label(bal_lbl_row, "Balance (ETB)", F_SMALL, bg=C_GREEN_HDR, color="#e8f5e9").pack(side="left")
        eye_btn = make_label(bal_lbl_row, "  👁", F_NORMAL, bg=C_GREEN_HDR, color=C_WHITE)
        eye_btn.pack(side="left")
        eye_btn.bind("<Button-1>", lambda e: self._toggle_balance())
        eye_btn.configure(cursor="hand2")

        self.bal_display = make_label(bal_frame, "* * * * * * *", F_LARGE + 2, bold=True,
                                       bg=C_GREEN_HDR, color=C_WHITE)
        self.bal_display.pack(pady=4)

        sub_row = make_frame(bal_frame, bg=C_GREEN_HDR)
        sub_row.pack()
        self.endekise_lbl = make_label(sub_row, "Endekise (ETB) 👁   ******", F_SMALL - 1, bg=C_GREEN_HDR, color="#e8f5e9")
        self.endekise_lbl.pack(side="left", padx=10)
        self.reward_lbl = make_label(sub_row, "Reward (ETB) 👁   ******", F_SMALL - 1, bg=C_GREEN_HDR, color="#e8f5e9")
        self.reward_lbl.pack(side="right", padx=10)

        make_label(root, "ONE APP FOR ALL YOUR NEEDS  |  Up to 25%", F_SMALL - 1, bold=True,
                   bg=C_ORANGE, color=C_WHITE).pack(fill="x")

        canvas = tk.Canvas(root, bg=C_WHITE, highlightthickness=0)
        canvas.pack(fill="both", expand=True)
        scroll_frame = make_frame(canvas, bg=C_WHITE)
        canvas.create_window((0, 0), window=scroll_frame, anchor="nw")
        scroll_frame.bind("<Configure>", lambda e: canvas.configure(scrollregion=canvas.bbox("all")))
        canvas.bind("<MouseWheel>", lambda e: canvas.yview_scroll(-1 * (e.delta // 120), "units"))

        svcs = make_frame(scroll_frame, bg=C_WHITE)
        svcs.pack(fill="x", padx=6, pady=8)
        services = [
            ("💸", "Send Money",          self._open_transfer),
            ("🏧", "Cash In/Out",         lambda: self._open_add_balance()),
            ("📱", "Airtime/Buy\nPackage", self._open_airtime),
            ("🏪", "Broker System\n", self._open_marketplace),
            ("🏦", "Financial\nDashen",   lambda: self._open_service("Dashen Bank")),
            ("🪙", "Financial\nCBE",      lambda: self._open_service("CBE")),
            ("💹", "Financial\nSiinqee",  lambda: self._open_service("Siinqee")),
            ("🏛", "Transfer\nto Bank",   self._open_transfer),
        ]
        for i, (icon, label, cmd) in enumerate(services):
            col = i % 4
            row_n = i // 4
            cell = make_frame(svcs, bg=C_WHITE, cursor="hand2")
            cell.grid(row=row_n, column=col, padx=4, pady=4, sticky="nsew")
            svcs.columnconfigure(col, weight=1)

            ic_frame = make_frame(cell, bg="#f0f0f0", width=50, height=50)
            ic_frame.pack(pady=(6, 2))
            ic_frame.pack_propagate(False)
            ic_lbl = make_label(ic_frame, icon, F_LARGE, bg="#f0f0f0", color=C_TEXT)
            ic_lbl.place(relx=0.5, rely=0.5, anchor="center")

            make_label(cell, label, F_SMALL - 1, bg=C_WHITE, color=C_TEXT,
                       justify="center", wraplength=74).pack()
            cell.bind("<Button-1>", lambda e, c=cmd: c())
            ic_lbl.bind("<Button-1>", lambda e, c=cmd: c())

        separator(scroll_frame, bg=C_BORDER).pack(fill="x", pady=4)

        promo = make_frame(scroll_frame, bg="#2e7d32")
        promo.pack(fill="x", padx=10, pady=4)
        make_label(promo, "Above", F_SMALL, bg="#2e7d32", color="#c8e6c9").pack(anchor="w", padx=12, pady=(8, 0))
        make_label(promo, "60 Million ETB", F_LARGE, bold=True, bg="#2e7d32", color=C_WHITE).pack(anchor="w", padx=12)
        make_label(promo, "  Prizes!  ", F_SMALL, bold=True, bg=C_BLUE, color=C_WHITE).pack(anchor="w", padx=12, pady=(2, 8))

        separator(scroll_frame, bg=C_BORDER).pack(fill="x", pady=4)

        make_button(scroll_frame, "💰 Add Balance", self._open_add_balance,
                   bg=C_BLUE, size=F_MEDIUM, pad_y=10).pack(fill="x", padx=10, pady=6)

        make_button(scroll_frame, "🏪 Marketplace (Ethio Brokerplace)", self._open_marketplace,
                   bg=C_ORANGE, size=F_MEDIUM, pad_y=10).pack(fill="x", padx=10, pady=6)

        make_frame(scroll_frame, bg=C_BORDER, height=1).pack(fill="x")
        self.txn_frame = make_frame(scroll_frame, bg=C_WHITE)
        self.txn_frame.pack(fill="x")
        self._refresh_txn_list()

    # ─────────────────────────────────────────────────────────────────────────
    #  MARKETPLACE WITH PHP BACKEND
    # ─────────────────────────────────────────────────────────────────────────
    def _open_marketplace(self):
        if not self.current_user:
            messagebox.showerror("Error", "Please login first")
            return
        
        win = self._dialog("Ethio Brokerplace Payment", 420, 580)
        
        hdr = make_frame(win, bg=C_ORANGE)
        hdr.pack(fill="x")
        make_label(hdr, "🏪 Ethio Brokerplace", F_MEDIUM, bold=True, bg=C_ORANGE, color=C_WHITE).pack(pady=12)
        make_label(hdr, "Secure Escrow Payment", F_SMALL, bg=C_ORANGE, color="#fff3e0").pack(pady=(0, 8))
        
        body = make_frame(win, bg=C_WHITE)
        body.pack(fill="both", expand=True, padx=20, pady=12)
        
        make_label(body, "Enter 5-Digit Payment Code", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w")
        
        code_frame = make_frame(body, bg=C_WHITE)
        code_frame.pack(pady=(4, 12))
        code_vars = []
        code_entries = []
        for i in range(5):
            v = tk.StringVar()
            code_vars.append(v)
            e = tk.Entry(code_frame, textvariable=v, font=("Segoe UI", 24, "bold"),
                         width=2, justify="center", relief="solid", bd=2, highlightcolor=C_ORANGE)
            e.pack(side="left", padx=3, ipady=8)
            code_entries.append(e)
        
        def on_key(event, idx):
            val = code_vars[idx].get()
            if val and len(val) > 0 and idx < 4:
                code_entries[idx + 1].focus()
            if event.keysym == "BackSpace" and not val and idx > 0:
                code_entries[idx - 1].focus()
                code_entries[idx - 1].delete(0, tk.END)
        
        for i, e in enumerate(code_entries):
            e.bind("<KeyRelease>", lambda ev, idx=i: on_key(ev, idx))
        code_entries[0].focus()
        
        amount_label = make_label(body, "", F_MEDIUM, bold=True, bg=C_WHITE, color=C_GREEN)
        amount_label.pack(pady=8)
        
        merchant_label = make_label(body, "", F_SMALL, bg=C_WHITE, color=C_MUTED)
        merchant_label.pack()
        
        status_label = make_label(body, "", F_SMALL, bg=C_WHITE, color=C_RED)
        status_label.pack(pady=4)
        
        current_code = [None]
        current_amount = [None]
        
        def verify_code():
            code = "".join([v.get().strip() for v in code_vars])
            
            if len(code) != 5 or not code.isdigit():
                status_label.config(text="✗ Enter a valid 5-digit code", fg=C_RED)
                return
            
            status_label.config(text="⏳ Verifying...", fg=C_BLUE)
            win.update()
            
            result = self.call_php_api("verify_simple.php", {"payment_code": code})
            
            if result.get("success"):
                amount = float(result.get("amount", 0))
                amount_label.config(text=f"Amount: {amount:,.2f} ETB", fg=C_GREEN)
                merchant_label.config(text="Merchant: Ethio Brokerplace")
                status_label.config(text="✓ Verified! Click Pay Now", fg=C_GREEN)
                
                current_code[0] = code
                current_amount[0] = amount
                
                pay_btn.config(state="normal", bg=C_ORANGE)
            else:
                error_msg = result.get("error", "Invalid code")
                status_label.config(text=f"✗ {error_msg}", fg=C_RED)
        
        verify_btn = make_button(body, "Verify Code", verify_code, bg=C_BLUE, size=F_MEDIUM, pad_y=10)
        verify_btn.pack(fill="x", pady=8)
        
        def process_payment():
            if current_code[0] is None:
                status_label.config(text="✗ Verify code first", fg=C_RED)
                return
            self._show_pin_dialog("Confirm Payment", current_amount[0], 
                                  lambda pin: self._confirm_payment(current_code[0], pin, win))
        
        pay_btn = make_button(body, "Pay Now", process_payment, bg=C_GREEN, size=F_MEDIUM, pad_y=10, state="disabled")
        pay_btn.pack(fill="x", pady=8)
        
        info_frame = make_frame(body, bg="#f5f5f5")
        info_frame.pack(fill="x", pady=12)
        make_label(info_frame, "How to pay:", F_SMALL, bold=True, bg="#f5f5f5", color=C_MUTED).pack(anchor="w", padx=10, pady=(5,0))
        make_label(info_frame, "1. Get a code from Brokerplace website", F_SMALL - 1, bg="#f5f5f5", color=C_MUTED).pack(anchor="w", padx=15)
        make_label(info_frame, "2. Enter the code above and click Verify", F_SMALL - 1, bg="#f5f5f5", color=C_MUTED).pack(anchor="w", padx=15)
        make_label(info_frame, "3. Click Pay Now and enter your PIN", F_SMALL - 1, bg="#f5f5f5", color=C_MUTED).pack(anchor="w", padx=15)

    def _show_pin_dialog(self, title, amount, callback):
        win = self._dialog(f"{title}", 360, 480)
        win.configure(bg=C_WHITE)

        make_label(win, title, F_MEDIUM, bold=True, bg=C_WHITE, color=C_TEXT).pack(pady=(16, 4))
        if amount > 0:
            make_label(win, f"Amount: {amount:,.2f} ETB", F_SMALL, bg=C_WHITE, color=C_MUTED).pack()
        make_label(win, "Enter your 4-digit PIN", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(pady=(4, 12))

        dots_frame = make_frame(win, bg=C_WHITE)
        dots_frame.pack(pady=8)
        dot_labels = []
        for _ in range(4):
            d = make_frame(dots_frame, bg=C_BORDER, width=20, height=20)
            d.pack(side="left", padx=10)
            d.pack_propagate(False)
            dot_labels.append(d)

        pin_value = []
        error_label = make_label(win, "", F_SMALL, bg=C_WHITE, color=C_RED)
        error_label.pack(pady=4)

        def update_dots():
            for i, d in enumerate(dot_labels):
                if i < len(pin_value):
                    d.config(bg=C_GREEN)
                else:
                    d.config(bg=C_BORDER)

        def key_press(digit):
            if len(pin_value) >= 4:
                return
            pin_value.append(digit)
            update_dots()
            if len(pin_value) == 4:
                entered_pin = "".join(pin_value)
                win.after(300, lambda: (win.destroy(), callback(entered_pin)))

        def clear_pin():
            pin_value.clear()
            update_dots()
            error_label.config(text="")

        def backspace():
            if pin_value:
                pin_value.pop()
                update_dots()
                error_label.config(text="")

        pad_frame = make_frame(win, bg=C_WHITE)
        pad_frame.pack(pady=12)
        
        keys = [("1","2","3"), ("4","5","6"), ("7","8","9"), ("C", "0", "⌫")]
        for row_keys in keys:
            row_f = make_frame(pad_frame, bg=C_WHITE)
            row_f.pack(pady=3)
            for k in row_keys:
                if k == "C":
                    btn = tk.Button(row_f, text=k, font=("Segoe UI", F_MEDIUM, "bold"),
                                   bg="#f0f0f0", fg=C_RED, relief="flat", bd=0,
                                   width=4, height=2, cursor="hand2", command=clear_pin)
                    btn.pack(side="left", padx=4)
                elif k == "⌫":
                    btn = tk.Button(row_f, text="⌫", font=("Segoe UI", F_MEDIUM, "bold"),
                                   bg="#f0f0f0", fg=C_MUTED, relief="flat", bd=0,
                                   width=4, height=2, cursor="hand2", command=backspace)
                    btn.pack(side="left", padx=4)
                else:
                    btn = tk.Button(row_f, text=k, font=("Segoe UI", F_LARGE, "bold"),
                                   bg="#f5f5f5", fg=C_TEXT, relief="flat", bd=0,
                                   width=4, height=2, cursor="hand2",
                                   command=lambda d=k: key_press(d))
                    btn.pack(side="left", padx=4)

        make_button(win, "Cancel", win.destroy, bg=C_WHITE, fg=C_MUTED, size=F_SMALL, pad_y=4).pack()

    def _confirm_payment(self, code, pin, win, payment_type='deposit'):
        """Confirm payment - Telebirr verifies PIN locally"""
        
        # Telebirr app verifies PIN locally
        if pin != self.current_user.get("pin", "1234"):
            messagebox.showerror("Error", "Incorrect PIN!", parent=win)
            return
        
        user_phone = self.current_user.get("phone", "")
        
        # Show loading
        status_label = make_label(win, "Processing payment...", F_SMALL, bg=C_WHITE, color=C_BLUE)
        status_label.pack(pady=10)
        win.update()
        
        # Call API WITHOUT PIN
        result = self.call_php_api("process_payment.php", {
            "payment_code": code,
            "user_phone": user_phone,
            "payment_type": payment_type
        })
        
        if result.get("success"):
            amount = float(result.get("amount", 0))
            item_name = result.get("item_name", "Item")
            message = result.get("message", "Payment successful!")
            receipt = result.get("receipt", {})
            
            # Deduct from local Telebirr balance
            current_balance = float(self.current_user.get("balance", 0))
            new_balance = current_balance - amount
            
            self.storage.update_balance(self.current_user["phone"], new_balance, "set")
            self.current_user["balance"] = new_balance
            
            phone = self.current_user["phone"]
            if phone in DEMO_USERS:
                DEMO_USERS[phone]["balance"] = new_balance
            
            self.storage.add_transaction(
                self.current_user["phone"], 
                "escrow_payment", 
                amount, 
                0, 
                f"Payment to Brokerplace - {item_name}"
            )
            
            self._refresh_home()
            self._refresh_txn_list()
            win.destroy()
            
            self._show_payment_receipt(item_name, amount, receipt, message)
        else:
            error_msg = result.get("error", "Payment failed")
            status_label.config(text=f"✗ {error_msg}", fg=C_RED)

    def _show_payment_receipt(self, item_name, amount, receipt, custom_message):
        """Show detailed payment receipt"""
        receipt_win = self._dialog("Payment Receipt", 380, 550)
        receipt_win.configure(bg=C_WHITE)
        
        amount = float(amount)
        
        hdr = make_frame(receipt_win, bg=C_GREEN)
        hdr.pack(fill="x")
        make_label(hdr, "✓ PAYMENT SUCCESSFUL", F_MEDIUM, bold=True, bg=C_GREEN, color=C_WHITE).pack(pady=(16, 4))
        make_label(hdr, f"{amount:,.2f} ETB", F_LARGE, bold=True, bg=C_GREEN, color=C_WHITE).pack(pady=(2, 14))
        
        body = make_frame(receipt_win, bg=C_WHITE)
        body.pack(fill="both", expand=True, padx=20, pady=12)
        
        msg_frame = make_frame(body, bg="#e8f5e9")
        msg_frame.pack(fill="x", pady=10)
        make_label(msg_frame, custom_message, F_SMALL, bg="#e8f5e9", color="#2e7d32", wraplength=320).pack(padx=10, pady=10)
        
        receipt_frame = make_frame(body, bg="#f5f5f5")
        receipt_frame.pack(fill="x", pady=10)
        make_label(receipt_frame, "Receipt Details", F_SMALL, bold=True, bg="#f5f5f5", color=C_TEXT).pack(anchor="w", padx=10, pady=(10, 5))
        
        details = [
            ("Item:", item_name),
            ("Amount:", f"{amount:,.2f} ETB"),
            ("Transaction ID:", f"#{receipt.get('transaction_id', 'N/A')}"),
            ("Payment Code:", receipt.get('payment_code', 'N/A')),
            ("Date:", receipt.get('date', datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"))),
            ("Status:", "✓ Completed")
        ]
        
        for label, value in details:
            row = make_frame(receipt_frame, bg="#f5f5f5")
            row.pack(fill="x", padx=10, pady=3)
            make_label(row, label, F_SMALL, bold=True, bg="#f5f5f5", color=C_MUTED).pack(side="left")
            make_label(row, str(value), F_SMALL, bg="#f5f5f5", color=C_TEXT).pack(side="right")
        
        next_frame = make_frame(body, bg="#e3f2fd")
        next_frame.pack(fill="x", pady=10)
        make_label(next_frame, "What's Next?", F_SMALL, bold=True, bg="#e3f2fd", color="#1565c0").pack(anchor="w", padx=10, pady=(10, 5))
        make_label(next_frame, "• Your payment is held in escrow\n• Seller will be notified to deliver\n• Confirm delivery to release payment", 
                   F_SMALL - 1, bg="#e3f2fd", color="#333", justify="left").pack(anchor="w", padx=10, pady=(0, 10))
        
        close_btn = tk.Button(receipt_win, text="Close", command=receipt_win.destroy,
                             bg=C_GREEN, fg=C_WHITE, font=("Segoe UI", F_MEDIUM, "bold"),
                             relief="flat", bd=0, cursor="hand2", padx=20, pady=10)
        close_btn.pack(fill="x", padx=20, pady=12)

    def _refresh_home(self):
        if self.current_user:
            name = self.current_user.get("full_name", "User")
            self.home_greet.config(text=f"Selam, {name}")
        self.balance_visible = False
        self.bal_display.config(text="* * * * * * *")
        self._refresh_txn_list()

    def _toggle_balance(self):
        self.balance_visible = not self.balance_visible
        if self.current_user:
            bal = self.current_user.get("balance", 0)
            end = self.current_user.get("endekise", 0)
            rew = self.current_user.get("rewards", 0)
            if self.balance_visible:
                self.bal_display.config(text=f"{bal:,.2f}")
                self.endekise_lbl.config(text=f"Endekise (ETB) 👁   {end:,.2f}")
                self.reward_lbl.config(text=f"Reward (ETB) 👁   {rew:,.2f}")
            else:
                self.bal_display.config(text="* * * * * * *")
                self.endekise_lbl.config(text="Endekise (ETB) 👁   ******")
                self.reward_lbl.config(text="Reward (ETB) 👁   ******")

    def _refresh_txn_list(self):
        for w in self.txn_frame.winfo_children():
            w.destroy()
        
        txn_hdr = make_frame(self.txn_frame, bg=C_WHITE)
        txn_hdr.pack(fill="x", padx=12, pady=6)
        make_label(txn_hdr, "Recent Transactions", F_SMALL, bold=True, bg=C_WHITE, color=C_TEXT).pack(side="left")

        if self.current_user:
            txns = self.storage.get_transactions(self.current_user["phone"], 5)
            for txn in txns:
                row = make_frame(self.txn_frame, bg=C_WHITE)
                row.pack(fill="x", padx=12, pady=3)
                separator(self.txn_frame, "#f0f0f0").pack(fill="x", padx=12)
                
                color = C_GREEN if "add" in txn["type"] else C_RED
                sign = "+" if "add" in txn["type"] else "-"
                make_label(row, f"{sign}{txn['amount']:,.0f} ETB", F_SMALL, bold=True,
                          bg=C_WHITE, color=color).pack(side="left")
                make_label(row, txn["description"][:30], F_SMALL - 1, bg=C_WHITE, color=C_MUTED).pack(side="right")

    # ─────────────────────────────────────────────────────────────────────────
    #  OTHER METHODS (Transfers, Airtime, Bills, etc.)
    # ─────────────────────────────────────────────────────────────────────────
    def _open_service(self, name):
        messagebox.showinfo(name, f"{name} service available at your nearest Ethio telecom center!")

    def _open_transfer(self):
        if not self.current_user:
            messagebox.showerror("Error", "Please login first")
            return
        
        win = self._dialog("Send Money", 400, 420)
        make_label(win, "Recipient Phone", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(14, 2))
        rec_var = tk.StringVar()
        tk.Entry(win, textvariable=rec_var, font=("Segoe UI", F_MEDIUM),
                 relief="solid", bd=1).pack(fill="x", padx=20, pady=(0, 10), ipady=8)
        make_label(win, "Amount (ETB)", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(0, 2))
        amt_var = tk.StringVar()
        tk.Entry(win, textvariable=amt_var, font=("Segoe UI", F_MEDIUM),
                 relief="solid", bd=1).pack(fill="x", padx=20, pady=(0, 10), ipady=8)
        make_label(win, "Reason (optional)", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(0, 2))
        reason_var = tk.StringVar()
        tk.Entry(win, textvariable=reason_var, font=("Segoe UI", F_NORMAL),
                 relief="solid", bd=1).pack(fill="x", padx=20, pady=(0, 16), ipady=6)

        def send():
            try:
                amt = float(amt_var.get())
            except ValueError:
                messagebox.showerror("Error", "Enter a valid amount", parent=win)
                return
            rec = rec_var.get().strip()
            if not rec:
                messagebox.showerror("Error", "Enter recipient phone", parent=win)
                return
            if amt > self.current_user["balance"]:
                messagebox.showerror("Insufficient Funds", f"Balance: {self.current_user['balance']:,.2f} ETB", parent=win)
                return
            win.destroy()
            self._show_pin_dialog("Confirm Transfer", amt, lambda pin: self._process_transfer(rec, amt, pin, reason_var.get()))

        make_button(win, "Continue", send, bg=C_BLUE, size=F_MEDIUM, pad_y=10).pack(fill="x", padx=20)

    def _process_transfer(self, receiver, amount, pin, reason):
        if pin != self.current_user.get("pin", "1234"):
            messagebox.showerror("Error", "Incorrect PIN!")
            return
        
        self.storage.update_balance(self.current_user["phone"], amount, "subtract")
        self.storage.add_transaction(self.current_user["phone"], "transfer_out", amount, 0, reason or "Money transfer")
        self.current_user["balance"] -= amount
        phone = self.current_user["phone"]
        if phone in DEMO_USERS:
            DEMO_USERS[phone]["balance"] -= amount
        self._refresh_home()
        self._refresh_txn_list()
        self._show_receipt("Money Sent", amount, receiver, f"TRF{random.randint(100000,999999)}")

    def _open_airtime(self):
        if not self.current_user:
            messagebox.showerror("Error", "Please login first")
            return
        
        win = self._dialog("Buy Airtime", 380, 360)
        make_label(win, "Phone Number", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(14, 2))
        phone_var = tk.StringVar(value=self.current_user.get("phone",""))
        tk.Entry(win, textvariable=phone_var, font=("Segoe UI", F_MEDIUM),
                 relief="solid", bd=1).pack(fill="x", padx=20, pady=(0, 10), ipady=8)
        make_label(win, "Amount (ETB)", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(0, 2))
        amounts = [10, 25, 50, 100, 200]
        grid = make_frame(win, bg=C_WHITE)
        grid.pack(padx=20, pady=(0, 12))
        amt_var = tk.StringVar(value="50")
        for i, a in enumerate(amounts):
            rb = tk.Radiobutton(grid, text=f"{a} ETB", variable=amt_var, value=str(a),
                                font=("Segoe UI", F_SMALL, "bold"), bg=C_WHITE,
                                selectcolor=C_LIGHT_GREEN, relief="solid", bd=1,
                                padx=12, pady=6, cursor="hand2", indicatoron=0)
            rb.grid(row=i//3, column=i%3, padx=3, pady=3)

        def buy():
            amt = float(amt_var.get())
            if amt > self.current_user["balance"]:
                messagebox.showerror("Insufficient Funds", f"Balance: {self.current_user['balance']:,.2f} ETB", parent=win)
                return
            win.destroy()
            self._show_pin_dialog("Confirm Airtime", amt, lambda pin: self._process_airtime(phone_var.get(), amt, pin))

        make_button(win, "Buy Airtime", buy, bg=C_GREEN, size=F_MEDIUM, pad_y=10).pack(fill="x", padx=20)

    def _process_airtime(self, phone, amount, pin):
        if pin != self.current_user.get("pin", "1234"):
            messagebox.showerror("Error", "Incorrect PIN!")
            return
        self.storage.update_balance(self.current_user["phone"], amount, "subtract")
        self.storage.add_transaction(self.current_user["phone"], "airtime", amount, 0, f"Airtime {phone}")
        self.current_user["balance"] -= amount
        if self.current_user["phone"] in DEMO_USERS:
            DEMO_USERS[self.current_user["phone"]]["balance"] -= amount
        self._refresh_home()
        self._refresh_txn_list()
        self._show_receipt("Airtime Purchased", amount, phone, f"AIR{random.randint(100000,999999)}")

    def _open_bill(self, bill_type):
        if not self.current_user:
            messagebox.showerror("Error", "Please login first")
            return
        
        win = self._dialog(f"Pay {bill_type}", 380, 340)
        make_label(win, "Account / Meter Number", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(14, 2))
        acc_var = tk.StringVar()
        tk.Entry(win, textvariable=acc_var, font=("Segoe UI", F_MEDIUM),
                 relief="solid", bd=1).pack(fill="x", padx=20, pady=(0, 10), ipady=8)
        make_label(win, "Amount (ETB)", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(0, 2))
        amt_var = tk.StringVar()
        tk.Entry(win, textvariable=amt_var, font=("Segoe UI", F_MEDIUM),
                 relief="solid", bd=1).pack(fill="x", padx=20, pady=(0, 16), ipady=8)

        def pay():
            try:
                amt = float(amt_var.get())
            except ValueError:
                messagebox.showerror("Error", "Enter valid amount", parent=win)
                return
            if amt > self.current_user["balance"]:
                messagebox.showerror("Insufficient Funds", f"Balance: {self.current_user['balance']:,.2f} ETB", parent=win)
                return
            win.destroy()
            self._show_pin_dialog(f"Pay {bill_type}", amt, lambda pin: self._process_bill(bill_type, acc_var.get(), amt, pin))

        make_button(win, f"Pay {bill_type}", pay, bg=C_BLUE, size=F_MEDIUM, pad_y=10).pack(fill="x", padx=20)

    def _process_bill(self, bill_type, account, amount, pin):
        if pin != self.current_user.get("pin", "1234"):
            messagebox.showerror("Error", "Incorrect PIN!")
            return
        self.storage.update_balance(self.current_user["phone"], amount, "subtract")
        self.storage.add_transaction(self.current_user["phone"], "bill", amount, 0, f"{bill_type} - {account}")
        self.current_user["balance"] -= amount
        if self.current_user["phone"] in DEMO_USERS:
            DEMO_USERS[self.current_user["phone"]]["balance"] -= amount
        self._refresh_home()
        self._refresh_txn_list()
        self._show_receipt(f"{bill_type} Bill Paid", amount, account, f"BILL{random.randint(100000,999999)}")

    def _open_pay_code(self):
        if not self.current_user:
            messagebox.showerror("Error", "Please login first")
            return
        
        win = self._dialog("Pay with Code", 380, 340)
        make_label(win, "Enter 5-Digit Payment Code", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(14, 2))

        code_frame = make_frame(win, bg=C_WHITE)
        code_frame.pack(padx=20, pady=(0, 16))
        code_vars = []
        code_entries = []
        for i in range(5):
            v = tk.StringVar()
            code_vars.append(v)
            e = tk.Entry(code_frame, textvariable=v, font=("Segoe UI", 24, "bold"),
                         width=2, justify="center", relief="solid", bd=2)
            e.pack(side="left", padx=3, ipady=8)
            code_entries.append(e)

        def on_key(event, idx):
            val = code_vars[idx].get()
            if val and idx < 4:
                code_entries[idx + 1].focus()
            if event.keysym == "BackSpace" and not val and idx > 0:
                code_entries[idx - 1].focus()

        for i, e in enumerate(code_entries):
            e.bind("<KeyRelease>", lambda ev, idx=i: on_key(ev, idx))
        code_entries[0].focus()

        def confirm():
            code = "".join(v.get() for v in code_vars)
            if len(code) < 5 or not code.isdigit():
                messagebox.showerror("Error", "Enter a valid 5-digit code", parent=win)
                return
            win.destroy()
            self._show_pin_dialog("Confirm Payment", 500, lambda pin: self._process_code_payment(500, pin, code))

        make_button(win, "Confirm Code", confirm, bg=C_BLUE, size=F_MEDIUM, pad_y=10).pack(fill="x", padx=20)

    def _process_code_payment(self, amount, pin, code):
        if pin != self.current_user.get("pin", "1234"):
            messagebox.showerror("Error", "Incorrect PIN!")
            return
        if self.current_user["balance"] < amount:
            messagebox.showerror("Insufficient Funds", f"Balance: {self.current_user['balance']:,.2f} ETB")
            return
        self.storage.update_balance(self.current_user["phone"], amount, "subtract")
        self.storage.add_transaction(self.current_user["phone"], "code_payment", amount, 0, f"Code: {code}")
        self.current_user["balance"] -= amount
        self._refresh_home()
        self._refresh_txn_list()
        self._show_receipt("Code Payment", amount, f"Code: {code}", f"CP{random.randint(100000,999999)}")

    def _open_change_pin(self):
        win = self._dialog("Change PIN", 360, 320)
        make_label(win, "Current PIN", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(14, 2))
        cur_var = tk.StringVar()
        tk.Entry(win, textvariable=cur_var, show="●", font=("Segoe UI", F_MEDIUM),
                 relief="solid", bd=1).pack(fill="x", padx=20, pady=(0, 10), ipady=8)
        make_label(win, "New PIN (4 digits)", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(0, 2))
        new_var = tk.StringVar()
        tk.Entry(win, textvariable=new_var, show="●", font=("Segoe UI", F_MEDIUM),
                 relief="solid", bd=1).pack(fill="x", padx=20, pady=(0, 10), ipady=8)
        make_label(win, "Confirm New PIN", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(0, 2))
        conf_var = tk.StringVar()
        tk.Entry(win, textvariable=conf_var, show="●", font=("Segoe UI", F_MEDIUM),
                 relief="solid", bd=1).pack(fill="x", padx=20, pady=(0, 16), ipady=8)

        def change():
            if cur_var.get() != self.current_user.get("pin", "1234"):
                messagebox.showerror("Error", "Current PIN incorrect", parent=win)
                return
            if len(new_var.get()) != 4 or not new_var.get().isdigit():
                messagebox.showerror("Error", "New PIN must be 4 digits", parent=win)
                return
            if new_var.get() != conf_var.get():
                messagebox.showerror("Error", "PINs do not match", parent=win)
                return
            self.current_user["pin"] = new_var.get()
            win.destroy()
            messagebox.showinfo("Success", "PIN changed successfully!")

        make_button(win, "Change PIN", change, bg=C_GREEN, size=F_MEDIUM, pad_y=10).pack(fill="x", padx=20)

    # ─────────────────────────────────────────────────────────────────────────
    #  OTHER TABS (Payment, Apps, Engage, Account)
    # ─────────────────────────────────────────────────────────────────────────
    def _build_payment_tab(self):
        root = make_frame(self.tab_container, bg=C_BG)
        self.tabs["payment"] = root

        hdr = make_frame(root, bg=C_GREEN_HDR)
        hdr.pack(fill="x")
        make_label(hdr, "Payment", F_TITLE, bold=True, bg=C_GREEN_HDR, color=C_WHITE).pack(side="left", padx=14, pady=12)

        body = make_frame(root, bg=C_WHITE)
        body.pack(fill="both", expand=True)

        items = [
            ("💸", "Send Money",       self._open_transfer),
            ("🏧", "Add Balance",      self._open_add_balance),
            ("📱", "Buy Airtime",      self._open_airtime),
            ("⚡", "Electricity",      lambda: self._open_bill("Electricity")),
            ("💧", "Water Bill",       lambda: self._open_bill("Water")),
            ("🌐", "Internet",         lambda: self._open_bill("Internet")),
            ("🏪", "Marketplace",      self._open_marketplace),
            ("🔢", "Pay with Code",    self._open_pay_code),
            ("🏦", "Bank Transfer",    self._open_transfer),
        ]
        cols = 3
        for i, (icon, label, cmd) in enumerate(items):
            col = i % cols
            row_n = i // cols
            cell = make_frame(body, bg=C_WHITE, cursor="hand2",
                              relief="solid", bd=0, highlightthickness=1,
                              highlightbackground=C_BORDER)
            cell.grid(row=row_n, column=col, padx=6, pady=6, sticky="nsew")
            body.columnconfigure(col, weight=1)

            make_label(cell, icon, F_LARGE + 4, bg=C_WHITE, color=C_TEXT).pack(pady=(12, 4))
            make_label(cell, label, F_SMALL - 1, bg=C_WHITE, color=C_TEXT, wraplength=90, justify="center").pack(pady=(0, 10))
            cell.bind("<Button-1>", lambda e, c=cmd: c())

    def _build_apps_tab(self):
        root = make_frame(self.tab_container, bg=C_BG)
        self.tabs["apps"] = root

        hdr = make_frame(root, bg=C_GREEN_HDR)
        hdr.pack(fill="x")
        make_label(hdr, "Apps", F_TITLE, bold=True, bg=C_GREEN_HDR, color=C_WHITE).pack(side="left", padx=14, pady=12)

        canvas = tk.Canvas(root, bg=C_WHITE, highlightthickness=0)
        canvas.pack(fill="both", expand=True)
        grid_frame = make_frame(canvas, bg=C_WHITE)
        canvas.create_window((0, 0), window=grid_frame, anchor="nw")
        grid_frame.bind("<Configure>", lambda e: canvas.configure(scrollregion=canvas.bbox("all")))
        canvas.bind("<MouseWheel>", lambda e: canvas.yview_scroll(-1 * (e.delta // 120), "units"))

        apps = [
            ("🏨", "#e8f5e9", "My Ethiotel"),
            ("T6", "#e3f2fd", "telebirrRemit"),
            ("🏠", "#e8f5e9", "tele-Online\nFixed Service"),
            ("✈️", "#fff3e0", "Ethiopian\nAirlines"),
            ("NID","#e8eaf6", "NID (Fayda)\nDigital ID"),
            ("📍", "#e8f5e9", "Teninete"),
            ("🏪", "#ff9800", "Marketplace\n(Ethio Brokerplace)"),
            ("NID","#e8eaf6", "NID (Fayda)\nprinting"),
            ("MK", "#212121", "MyDStv"),
            ("SIT","#e8f5e9", "SITOTA"),
            ("⭐", "#fff9c4", "E-Services"),
            ("RIDE","#fff3e0","RIDE"),
            ("🚌", "#e3f2fd", "Public\nTransport"),
            ("HB", "#e53935", "Hulu beje"),
            ("WS", "#212121", "WebSprix"),
            ("Z",  "#f44336", "Zmall"),
        ]
        cols = 3
        for i, (icon, bg, name) in enumerate(apps):
            col = i % cols
            row_n = i // cols
            cell = make_frame(grid_frame, bg=C_WHITE, cursor="hand2",
                              relief="solid", bd=0,
                              highlightthickness=1, highlightbackground=C_BORDER)
            cell.grid(row=row_n, column=col, padx=5, pady=5, sticky="nsew")
            grid_frame.columnconfigure(col, weight=1)

            ic_frame = make_frame(cell, bg=bg, width=46, height=46)
            ic_frame.pack(pady=(10, 4))
            ic_frame.pack_propagate(False)

            fg = C_WHITE if bg in ("#212121", "#e53935", "#f44336") else "#333"
            fs = F_SMALL - 1 if len(icon) > 2 else F_MEDIUM
            tk.Label(ic_frame, text=icon, font=("Segoe UI", fs, "bold"), bg=bg, fg=fg).place(relx=0.5, rely=0.5, anchor="center")

            make_label(cell, name, F_SMALL - 1, bg=C_WHITE, color=C_TEXT,
                       justify="center", wraplength=100).pack(pady=(0, 8))
            
            if "Marketplace" in name:
                cell.bind("<Button-1>", lambda e: self._open_marketplace())

    def _build_engage_tab(self):
        root = make_frame(self.tab_container, bg=C_BG)
        self.tabs["engage"] = root
        hdr = make_frame(root, bg=C_GREEN_HDR)
        hdr.pack(fill="x")
        make_label(hdr, "Engage", F_TITLE, bold=True, bg=C_GREEN_HDR, color=C_WHITE).pack(padx=14, pady=12)
        body = make_frame(root, bg=C_WHITE)
        body.pack(fill="both", expand=True)
        make_label(body, "💬", 32, bg=C_WHITE, color=C_MUTED).pack(pady=(80, 8))
        make_label(body, "Engage Hub", F_MEDIUM, bold=True, bg=C_WHITE, color=C_TEXT).pack()
        make_label(body, "Community features, promotions\nand rewards.", F_SMALL, bg=C_WHITE,
                   color=C_MUTED, justify="center").pack(pady=6)

    def _build_account_tab(self):
        root = make_frame(self.tab_container, bg=C_BG)
        self.tabs["account"] = root

        hdr = make_frame(root, bg=C_GREEN_HDR)
        hdr.pack(fill="x")
        hdr_row = make_frame(hdr, bg=C_GREEN_HDR)
        hdr_row.pack(fill="x", padx=14, pady=12)
        make_label(hdr_row, "Account", F_TITLE, bold=True, bg=C_GREEN_HDR, color=C_WHITE).pack(side="left")

        profile_card = make_frame(root, bg=C_WHITE, highlightthickness=1, highlightbackground=C_BORDER)
        profile_card.pack(fill="x", padx=10, pady=10)

        prow = make_frame(profile_card, bg=C_WHITE)
        prow.pack(fill="x", padx=12, pady=10)

        av_frame = make_frame(prow, bg=C_GREEN, width=46, height=46)
        av_frame.pack(side="left")
        av_frame.pack_propagate(False)
        tk.Label(av_frame, text="👤", font=("Segoe UI", F_LARGE), bg=C_GREEN, fg=C_WHITE).place(relx=0.5, rely=0.5, anchor="center")

        info_frame = make_frame(prow, bg=C_WHITE)
        info_frame.pack(side="left", padx=10, expand=True, fill="x")
        self.acc_name_lbl = make_label(info_frame, "User", F_MEDIUM, bold=True, bg=C_WHITE, color=C_TEXT)
        self.acc_name_lbl.pack(anchor="w")
        sub_row = make_frame(info_frame, bg=C_WHITE)
        sub_row.pack(anchor="w")
        make_label(sub_row, "Level 1", F_SMALL - 1, bold=True, bg=C_GREEN, color=C_WHITE).pack(side="left")
        self.acc_phone_lbl = make_label(sub_row, "", F_SMALL - 1, bg=C_WHITE, color=C_MUTED)
        self.acc_phone_lbl.pack(side="left", padx=6)

        canvas = tk.Canvas(root, bg=C_WHITE, highlightthickness=0)
        canvas.pack(fill="both", expand=True)
        menu_frame = make_frame(canvas, bg=C_WHITE)
        canvas.create_window((0, 0), window=menu_frame, anchor="nw")
        menu_frame.bind("<Configure>", lambda e: canvas.configure(scrollregion=canvas.bbox("all")))

        items = [
            ("💰", "Add Balance",                        self._open_add_balance),
            ("🏪", "Marketplace",                       self._open_marketplace),
            ("🔒", "Change PIN",                         self._open_change_pin),
            ("🌐", "Change Language",                    None, "English"),
            ("🆔", "Verify with Fayda (NID)",            None),
            ("❓", "FAQ",                                 None),
            ("💬", "Feedback",                           None),
            ("🚪", "Logout",                             self._logout),
        ]
        for entry in items:
            icon, label = entry[0], entry[1]
            cmd = entry[2] if len(entry) > 2 else None
            value = entry[3] if len(entry) > 3 else None

            row = make_frame(menu_frame, bg=C_WHITE, cursor="hand2")
            row.pack(fill="x")
            separator(menu_frame, "#f0f0f0").pack(fill="x")

            ic_bg = C_LIGHT_GREEN
            ic_fg = C_GREEN_DARK
            if icon in ("🚪",): ic_bg = "#ffebee"; ic_fg = C_RED

            ic_frame = make_frame(row, bg=ic_bg, width=34, height=34)
            ic_frame.pack(side="left", padx=(12, 0), pady=8)
            ic_frame.pack_propagate(False)
            tk.Label(ic_frame, text=icon, font=("Segoe UI", F_SMALL, "bold"), bg=ic_bg, fg=ic_fg).place(relx=0.5, rely=0.5, anchor="center")

            txt_lbl = make_label(row, label, F_SMALL, bg=C_WHITE, color=C_TEXT)
            txt_lbl.pack(side="left", padx=10, expand=True, fill="x")

            if value:
                make_label(row, value, F_SMALL - 1, bg=C_WHITE, color=C_MUTED).pack(side="right", padx=4)
            make_label(row, "›", F_MEDIUM, bg=C_WHITE, color=C_MUTED).pack(side="right", padx=12)

            if cmd:
                row.bind("<Button-1>", lambda e, c=cmd: c())
                txt_lbl.bind("<Button-1>", lambda e, c=cmd: c())

        self._refresh_account()

    def _refresh_account(self):
        if self.current_user:
            self.acc_name_lbl.config(text=self.current_user.get("full_name", "User"))
            self.acc_phone_lbl.config(text=self.current_user.get("phone", ""))
            level = self.current_user.get("level", 1)
            for child in self.acc_name_lbl.master.winfo_children():
                if isinstance(child, tk.Label) and "Level" in (child.cget("text") or ""):
                    child.config(text=f"Level {level}")

    def _open_add_balance(self):
        if not self.current_user:
            messagebox.showerror("Error", "Please login first")
            return
        
        win = self._dialog("Add Balance", 380, 380)

        make_label(win, "Add Money to Your Account", F_MEDIUM, bold=True, bg=C_WHITE, color=C_TEXT).pack(pady=(16, 4))
        make_label(win, f"Current Balance: {self.current_user['balance']:,.2f} ETB", 
                  F_SMALL, bg=C_WHITE, color=C_MUTED).pack(pady=(0, 12))

        make_label(win, "Select Amount", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20)
        
        amounts = [100, 500, 1000, 5000, 10000, 50000]
        amt_var = tk.StringVar(value="1000")
        
        grid = make_frame(win, bg=C_WHITE)
        grid.pack(padx=20, pady=(0, 12))
        for i, a in enumerate(amounts):
            col, row_n = i % 3, i // 3
            rb = tk.Radiobutton(grid, text=f"{a:,} ETB", variable=amt_var, value=str(a),
                                font=("Segoe UI", F_SMALL, "bold"), bg=C_WHITE, fg=C_TEXT,
                                selectcolor=C_LIGHT_GREEN, relief="solid", bd=1,
                                padx=12, pady=6, cursor="hand2",
                                indicatoron=0)
            rb.grid(row=row_n, column=col, padx=3, pady=3)

        make_label(win, "Or Enter Custom Amount", F_SMALL, bg=C_WHITE, color=C_MUTED).pack(anchor="w", padx=20, pady=(8, 0))
        custom_frame = make_frame(win, bg=C_WHITE, relief="solid", bd=1, highlightcolor=C_GREEN)
        custom_frame.pack(fill="x", padx=20, pady=(4, 12))
        custom_var = tk.StringVar()
        custom_entry = tk.Entry(custom_frame, textvariable=custom_var,
                               font=("Segoe UI", F_MEDIUM, "bold"),
                               bg=C_WHITE, relief="flat")
        custom_entry.pack(fill="x", padx=10, ipady=6)

        def add_funds():
            if custom_var.get():
                try:
                    amt = float(custom_var.get())
                except ValueError:
                    messagebox.showerror("Error", "Invalid amount", parent=win)
                    return
            else:
                amt = float(amt_var.get())
            
            if amt <= 0:
                messagebox.showerror("Error", "Amount must be positive", parent=win)
                return
            
            win.destroy()
            self._process_add_balance(amt)

        make_button(win, "Add Funds", add_funds, bg=C_GREEN, size=F_MEDIUM, pad_y=10).pack(fill="x", padx=20)
        make_label(win, "🔒 Secure Transaction", F_SMALL - 1, bg=C_WHITE, color=C_MUTED).pack(pady=6)

    def _process_add_balance(self, amount):
        self.storage.update_balance(self.current_user["phone"], amount, "add")
        self.storage.add_transaction(self.current_user["phone"], "balance_add", amount, 0, "Added balance")
        self.current_user["balance"] += amount
        phone = self.current_user["phone"]
        if phone in DEMO_USERS:
            DEMO_USERS[phone]["balance"] += amount
        self._refresh_home()
        self._refresh_txn_list()
        self._show_receipt("Balance Added", amount, "Your Account", f"ADD{random.randint(100000,999999)}")

    def _show_receipt(self, title, amount, to, ref):
        win = self._dialog("Receipt", 360, 400)
        now = datetime.datetime.now().strftime("%d %b %Y  %H:%M")

        hdr = make_frame(win, bg=C_GREEN)
        hdr.pack(fill="x")
        make_label(hdr, "✓", 28, bold=True, bg=C_GREEN, color=C_WHITE).pack(pady=(16, 4))
        make_label(hdr, title, F_MEDIUM, bold=True, bg=C_GREEN, color=C_WHITE).pack()
        make_label(hdr, f"{amount:,.2f} ETB", F_LARGE, bold=True, bg=C_GREEN, color=C_WHITE).pack(pady=(2, 14))

        body = make_frame(win, bg=C_WHITE)
        body.pack(fill="both", expand=True, padx=20, pady=12)

        rows = [
            ("To / Merchant", str(to)),
            ("Reference",     str(ref)[:20]),
            ("Date",          now),
            ("Status",        "✓ Completed"),
        ]
        for label, val in rows:
            r = make_frame(body, bg=C_WHITE)
            r.pack(fill="x", pady=5)
            make_label(r, label, F_SMALL, bg=C_WHITE, color=C_MUTED).pack(side="left")
            color = C_GREEN if val.startswith("✓") else C_TEXT
            make_label(r, val, F_SMALL, bold=True, bg=C_WHITE, color=color).pack(side="right")
            separator(body, bg="#f0f0f0").pack(fill="x")

        make_button(win, "Done", win.destroy, bg=C_GREEN, size=F_MEDIUM, pad_y=10).pack(fill="x", padx=20, pady=12)

    def _logout(self):
        if messagebox.askyesno("Logout", "Are you sure you want to logout?"):
            self.current_user = None
            self.balance_visible = False
            self.session = requests.Session()
            self.show_screen("login")

    def _dialog(self, title, w, h):
        win = tk.Toplevel(self)
        win.title(title)
        win.geometry(f"{w}x{h}")
        win.resizable(False, False)
        win.configure(bg=C_WHITE)
        win.grab_set()
        self.update_idletasks()
        x = self.winfo_x() + (self.winfo_width() - w) // 2
        y = self.winfo_y() + (self.winfo_height() - h) // 2
        win.geometry(f"+{x}+{y}")
        return win

    def on_close(self):
        self.destroy()


# ══════════════════════════════════════════════════════════════════════════════
#  RUN
# ══════════════════════════════════════════════════════════════════════════════
if __name__ == "__main__":
    app = TelebirrApp()
    app.mainloop()