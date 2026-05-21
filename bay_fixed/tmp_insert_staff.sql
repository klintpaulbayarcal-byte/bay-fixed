INSERT INTO users (fullname, email, username, password, role, must_change_password)
SELECT 'Jai', 'jai@example.com', 'jai', '$2y$10$dyg/XH9xMnrV/Z9r7Ko1JumDsxFk75at54j4OwgbCDW9eR0wqGZbW', 'staff', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'jai');

SELECT id, username, role, must_change_password FROM users WHERE username = 'jai';
