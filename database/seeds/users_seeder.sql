-- Users Seeder
-- Test data for users table

INSERT INTO users (name, email, username, password, role, status, age, department, salary) VALUES
('John Test', 'john.test@example.com', 'johntest', 'password123', 'user', 'active', 28, 'IT', 55000.00),
('Jane Test', 'jane.test@example.com', 'janetest', 'password123', 'admin', 'active', 32, 'HR', 65000.00),
('Test User', 'test.user@example.com', 'testuser', 'password123', 'user', 'active', 25, 'Marketing', 45000.00),
('Admin Test', 'admin.test@example.com', 'admintest', 'password123', 'admin', 'active', 35, 'IT', 75000.00),
('Demo Test', 'demo.test@example.com', 'demotest', 'password123', 'user', 'inactive', 29, 'Sales', 50000.00),
('Sample Test', 'sample.test@example.com', 'sampletest', 'password123', 'user', 'active', 27, 'IT', 52000.00),
('Testing User', 'testing.user@example.com', 'testinguser', 'password123', 'user', 'active', 30, 'Finance', 58000.00),
('Test Manager', 'test.manager@example.com', 'testmanager', 'password123', 'manager', 'active', 40, 'Operations', 70000.00),
('Quality Test', 'quality.test@example.com', 'qualitytest', 'password123', 'user', 'active', 26, 'QA', 48000.00),
('Final Test', 'final.test@example.com', 'finaltest', 'password123', 'user', 'active', 31, 'Development', 62000.00);