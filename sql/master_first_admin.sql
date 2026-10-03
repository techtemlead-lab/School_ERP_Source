-- Master Panel-এর প্রথম Super Admin (শুধু Master DB-তে import করুন)
-- Username: master   | পাসওয়ার্ড বিক্রেতা আলাদাভাবে দেবেন
-- ⚠️ প্রথম লগইনের পরই Master Panel > Change Password থেকে বদলে ফেলুন।
INSERT IGNORE INTO super_admin (username, password) VALUES ('master', '$2y$10$jwTTerUAyCl/tUvgO4pyZO/OZlbQW9sMH40KEJyRDvuIDWS.YNo5i');
