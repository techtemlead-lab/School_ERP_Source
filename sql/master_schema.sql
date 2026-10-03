-- =============================================================
-- School SaaS — Master Database Schema
-- =============================================================
-- এই DB টা আলাদা, শুধু "কোন school কোন DB তে আছে" এই ম্যাপিং রাখার
-- জন্য। প্রতিটা school-এর আসল ডাটা (students, finance, HR...)
-- তাদের নিজস্ব আলাদা DB তে থাকবে (schema.sql দিয়ে তৈরি)।
-- =============================================================

CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- পরিচিতি
    school_name     VARCHAR(200) NOT NULL,
    school_code     VARCHAR(50)  NOT NULL UNIQUE,   -- e.g. "school1" — subdomain এ ব্যবহার হবে

    -- Routing — কোনো একটা অবশ্যই থাকতে হবে
    subdomain       VARCHAR(100) NULL UNIQUE,        -- e.g. "school1" -> school1.yourdomain.com
    custom_domain   VARCHAR(150) NULL UNIQUE,        -- e.g. "erp.school1school.com" (client নিজের ডোমেইন ব্যবহার করলে)

    -- এই school এর ডাটা কোন DB তে আছে
    db_host         VARCHAR(100) NOT NULL DEFAULT 'localhost',
    db_name         VARCHAR(100) NOT NULL,
    db_user         VARCHAR(100) NOT NULL,
    db_pass         VARCHAR(255) NOT NULL,           -- ⚠️ production এ encrypt করে রাখা ভালো, নিচে নোট দেখো

    -- Subscription / lifecycle
    plan            ENUM('Trial','Basic','Standard','Premium') NOT NULL DEFAULT 'Trial',
    status          ENUM('Active','Suspended','Cancelled','Blocked') NOT NULL DEFAULT 'Active',
    trial_ends_at   DATE NULL,
    subscription_ends_at DATE NULL,

    -- Contact (owner/admin of that school)
    contact_name    VARCHAR(150) NULL,
    contact_phone   VARCHAR(30) NULL,
    contact_email   VARCHAR(150) NULL,

    -- Trash (soft-delete) — deleted_at সেট থাকলে এটা Trash-এ আছে ধরা হয়,
    -- client_list.php এ দেখাবে না, master/trash.php থেকে Restore করা যাবে
    deleted_at      DATETIME NULL DEFAULT NULL,
    deleted_by      INT NULL DEFAULT NULL,

    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Super-admin (তুমি নিজে) যারা Master Panel এ লগইন করবে school manage করতে
CREATE TABLE IF NOT EXISTS super_admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,   -- password_hash() দিয়ে সেভ হবে
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Domain Tracker — নিজের/ক্লায়েন্টের ডোমেইনগুলোর রেজিস্ট্রেশন/মেয়াদ/
-- রিনিউয়াল তথ্য রাখার টেবিল (master/domain_tracker.php)
CREATE TABLE IF NOT EXISTS domain_tracker (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    domain_name    VARCHAR(150) NOT NULL UNIQUE,
    tenant_id      INT NULL,                        -- ঐচ্ছিক — কোন স্কুলের সাথে যুক্ত
    registrar      VARCHAR(100) NULL,
    purchased_at   DATE NULL,
    expires_at     DATE NULL,
    renewal_cost   DECIMAL(10,2) NULL,
    auto_renew     TINYINT(1) NOT NULL DEFAULT 0,
    dns_provider   VARCHAR(100) NULL,
    nameservers    VARCHAR(255) NULL,
    notes          VARCHAR(255) NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL
);

-- নোট: উপরের deleted_at/deleted_by কলাম এবং status এনামের 'Blocked' ভ্যালু
-- নতুন ইনস্টলে সরাসরি এই স্কিমা দিয়েই তৈরি হবে। কিন্তু আগে থেকে চলা কোনো
-- লাইভ tenants টেবিলে এগুলো না থাকলেও সমস্যা নেই — master/_features.php এর
-- ensureTenantTrashColumns()/ensureTenantBlockedStatus() ফাংশন প্রথমবার
-- Master Panel ভিজিট করলেই নিজে থেকে ALTER TABLE করে যুক্ত করে দেবে
-- (কোনো ডেটা মুছে যাবে না বা ম্যানুয়ালি migration চালাতে হবে না)।
