-- YBT Management Software — Demo Seed Data
-- Comprehensive realistic dataset matching schema.sql exact column names

USE `ybt_management`;

-- 1. Default Business
INSERT INTO `businesses` (
  `id`, `name`, `legal_name`, `owner_name`, `type`, `industry`, 
  `registration_number`, `established_date`, `tagline`, `website`, `description`,
  `email`, `phone`, `whatsapp`,
  `address_line1`, `address_line2`, `landmark`, `city`, `district`, `state`, `postal_code`, `country`,
  `gst_registered`, `gstin`, `pan`, `tax_type`, `default_tax_rate`, `place_of_business`,
  `bank_name`, `account_holder`, `account_number`, `ifsc_code`, `bank_branch`, `upi_id`,
  `invoice_prefix`, `invoice_next_number`, `quotation_prefix`, `quotation_next_number`,
  `default_due_days`, `default_currency`, `default_notes`, `default_terms`, `is_demo`
) VALUES (
  1, 
  'Apex Infotech Solutions', 
  'Apex Infotech Solutions Private Limited', 
  'Vikram Malhotra', 
  'Private Limited', 
  'Information Technology & Consulting',
  'U72200MH2021PTC362819', 
  '2021-04-15', 
  'Empowering Digital Transformation', 
  'https://apexinfotech.example.com', 
  'Full-service technology consultancy providing enterprise web applications, cloud infrastructure, and AI automation.',
  'contact@apexinfotech.example.com', 
  '+91 98201 12345', 
  '+91 98201 12345',
  'Tech Park Plaza, Tower B, Suite 402', 
  'Andheri-Kurla Road', 
  'Near Chakala Metro Station', 
  'Mumbai', 
  'Mumbai Suburban', 
  'Maharashtra', 
  '400093', 
  'India',
  1, 
  '27AAACA1234A1Z5', 
  'AAACA1234A', 
  'GST', 
  18.00, 
  'Maharashtra (27)',
  'HDFC Bank', 
  'Apex Infotech Solutions Pvt Ltd', 
  '50200012345678', 
  'HDFC0000240', 
  'Andheri East Branch', 
  'apexinfotech@hdfcbank',
  'INV-2026-', 
  1006, 
  'QT-2026-', 
  1004,
  15, 
  'INR', 
  'Thank you for partnering with Apex Infotech Solutions. We appreciate your business!', 
  '1. Payment is due within 15 days of invoice date.\n2. Interest @ 18% p.a. will be levied on overdue payments.\n3. All disputes subject to Mumbai jurisdiction.', 
  1
) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 2. System Roles
INSERT INTO `roles` (`id`, `business_id`, `name`, `slug`, `description`, `is_system`) VALUES
(1, 1, 'Business Owner', 'owner', 'Full administrative authority and mutation approval', 1),
(2, 1, 'Operations Manager', 'manager', 'Day-to-day operations, invoicing, client management', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 3. Users (Password: password123)
INSERT INTO `users` (
  `id`, `business_id`, `role_id`, `name`, `email`, `phone`, 
  `password`, `is_owner`, `is_active`
) VALUES
(1, 1, 1, 'Vikram Malhotra', 'owner@ybt.com', '+91 98201 12345', '$2y$10$v9bn2FQbgixG4ardHYPxZ.i6Q5YCn6XI1wGKaKYKbwa/zZycfFPs6', 1, 1),
(2, 1, 2, 'Rohan Verma', 'manager@ybt.com', '+91 98202 54321', '$2y$10$v9bn2FQbgixG4ardHYPxZ.i6Q5YCn6XI1wGKaKYKbwa/zZycfFPs6', 0, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 4. Assign Manager Permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions` WHERE `key` IN (
  'dashboard.view',
  'customers.view', 'customers.create', 'customers.edit', 'customers.export',
  'leads.view', 'leads.create', 'leads.edit', 'leads.change_stage',
  'products.view', 'products.create', 'products.edit',
  'tasks.view', 'tasks.create', 'tasks.edit', 'tasks.complete',
  'quotations.view', 'quotations.create', 'quotations.edit',
  'invoices.view', 'invoices.create', 'invoices.issue', 'invoices.print', 'invoices.export',
  'payments.view', 'payments.record',
  'expenses.view', 'expenses.create', 'expenses.edit',
  'reports.view',
  'ai.access', 'ai.search'
);

-- 5. Customer Records
INSERT INTO `customers` (
  `id`, `business_id`, `name`, `company_name`, `email`, `phone`, `gstin`, 
  `billing_address_line1`, `billing_city`, `billing_state`, `billing_postal_code`, `status`
) VALUES
(1, 1, 'Rajesh Sharma', 'Nova Retail Enterprises', 'rajesh@novaretail.in', '+91 98111 22334', '27AABCN1234D1Z2', 'Plot 45, MIDC Industrial Area', 'Pune', 'Maharashtra', '411018', 'active'),
(2, 1, 'Priya Sundaram', 'Zenith Logistics Hub', 'priya@zenithlogistics.com', '+91 98440 99887', '29AABCZ5678E1Z9', '4th Block, Koramangala', 'Bengaluru', 'Karnataka', '560034', 'active'),
(3, 1, 'Amitabh Roy', 'BlueHorizon Media Lab', 'amitabh@bluehorizon.io', '+91 98300 44556', '19AABCB9012F1Z4', 'Salt Lake Sector V', 'Kolkata', 'West Bengal', '700091', 'active'),
(4, 1, 'Sunita Deshmukh', 'Acuity Healthcare Clinics', 'sunita@acuityhealth.org', '+91 98220 77665', '27AABCA3456G1Z1', 'MG Road, Camp', 'Pune', 'Maharashtra', '411001', 'active'),
(5, 1, 'Karan Mehta', 'Prime Builders & Developers', 'karan@primebuilders.co', '+91 98765 11223', '24AABCP7890H1Z8', 'SG Highway', 'Ahmedabad', 'Gujarat', '380054', 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 6. Products & Services Catalog
INSERT INTO `products` (
  `id`, `business_id`, `name`, `type`, `sku`, `hsn_sac`, 
  `selling_price`, `cost_price`, `tax_rate`, `unit`, `description`, `status`
) VALUES
(1, 1, 'Custom Web Application Development', 'service', 'SRV-WEB-01', '998314', 85000.00, 35000.00, 18.00, 'Project', 'Full-stack custom web platform design, development, and deployment.', 'active'),
(2, 1, 'Cloud Infrastructure Setup & Migration', 'service', 'SRV-CLD-02', '998315', 45000.00, 15000.00, 18.00, 'Project', 'AWS/GCP cloud setup, containerization, VPC security, and database migration.', 'active'),
(3, 1, 'Monthly Retainer — IT Support & SLA', 'service', 'SRV-RET-03', '998313', 25000.00, 8000.00, 18.00, 'Month', '24/7 server monitoring, proactive maintenance, bugfixes, and support.', 'active'),
(4, 1, 'UI/UX Design & Brand Prototyping', 'service', 'SRV-DSG-04', '998311', 35000.00, 12000.00, 18.00, 'Project', 'Figma user flows, interactive prototypes, and design systems.', 'active'),
(5, 1, 'Enterprise AI Chatbot Integration', 'service', 'SRV-AI-05', '998314', 60000.00, 20000.00, 18.00, 'Project', 'RAG-powered AI assistant connected to internal company documents.', 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 7. Leads Pipeline
INSERT INTO `leads` (
  `id`, `business_id`, `title`, `contact_name`, `company_name`, `email`, `phone`, 
  `deal_value`, `stage`, `source`, `notes`
) VALUES
(1, 1, 'ERP Upgrade for Retail Chain', 'Deepak Chopra', 'Metro Hypermarkets', 'deepak@metrohyper.in', '+91 98190 33445', 180000.00, 'proposal_sent', 'Referral', 'Sent comprehensive technical proposal; waiting for board review on Thursday.'),
(2, 1, 'AI Customer Support Agent', 'Meera Kapoor', 'Starlight FinServ', 'meera@starlightfinserv.com', '+91 98200 88990', 120000.00, 'negotiation', 'Website Form', 'Client requested demo of security rules and data isolation. Very enthusiastic.'),
(3, 1, 'Cloud Migration from On-Premise', 'Suresh Nair', 'Western Logistics', 'suresh@westernlog.in', '+91 98450 11335', 75000.00, 'qualified', 'Cold Outreach', 'Initial discovery call completed. Need to assess on-prem server specifications.'),
(4, 1, 'Brand Website Redesign', 'Ananya Sen', 'Sen Artisans Studio', 'ananya@senartisans.com', '+91 98311 66778', 40000.00, 'new', 'LinkedIn', 'Inquired about creative portfolio and e-commerce capabilities.')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- 8. Tasks & Reminders
INSERT INTO `tasks` (
  `id`, `business_id`, `title`, `description`, `assigned_to`, `due_date`, `priority`, `status`
) VALUES
(1, 1, 'Follow up with Metro Hypermarkets proposal', 'Check in with Deepak Chopra regarding board review and quotation feedback.', 1, CURDATE(), 'high', 'in_progress'),
(2, 1, 'Send monthly SLA invoice to Nova Retail', 'Generate recurring support invoice for November 2026.', 2, CURDATE(), 'urgent', 'pending'),
(3, 1, 'Review cloud security logs for Zenith Logistics', 'Inspect AWS VPC flow logs and verify automated database backup completion.', 2, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'medium', 'pending'),
(4, 1, 'Quarterly GST Reconciliation report', 'Compile GSTR-1 and GSTR-3B summary tables for tax advisor.', 1, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'high', 'pending')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- 9. Quotations
INSERT INTO `quotations` (
  `id`, `business_id`, `customer_id`, `quotation_number`, `quotation_date`, `validity_date`,
  `subtotal`, `tax_total`, `discount_total`, `grand_total`, `status`, `notes`
) VALUES
(1, 1, 1, 'QT-2026-1001', DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_ADD(CURDATE(), INTERVAL 15 DAY), 85000.00, 15300.00, 0.00, 100300.00, 'accepted', 'Quotation approved for Phase 1 portal.'),
(2, 1, 3, 'QT-2026-1002', DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 25 DAY), 45000.00, 8100.00, 0.00, 53100.00, 'sent', 'Awaiting client sign-off.'),
(3, 1, 4, 'QT-2026-1003', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 60000.00, 10800.00, 2000.00, 68800.00, 'draft', 'Draft proposal for AI Chatbot.')
ON DUPLICATE KEY UPDATE `quotation_number` = VALUES(`quotation_number`);

-- 10. Invoices
INSERT INTO `invoices` (
  `id`, `business_id`, `customer_id`, `invoice_number`, `invoice_date`, `due_date`,
  `subtotal`, `tax_total`, `discount_total`, `grand_total`, `amount_paid`, `outstanding`,
  `status`, `notes`
) VALUES
(1, 1, 1, 'INV-2026-1001', DATE_SUB(CURDATE(), INTERVAL 45 DAY), DATE_SUB(CURDATE(), INTERVAL 15 DAY), 85000.00, 15300.00, 0.00, 100300.00, 71800.00, 28500.00, 'overdue', 'Phase 1 Milestone Invoice. Part payment received.'),
(2, 1, 2, 'INV-2026-1002', DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 45000.00, 8100.00, 0.00, 53100.00, 53100.00, 0.00, 'paid', 'Cloud Migration Completed & Verified.'),
(3, 1, 3, 'INV-2026-1003', DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 20 DAY), 38135.59, 6864.41, 0.00, 45000.00, 0.00, 45000.00, 'sent', 'Brand Design Sprint.'),
(4, 1, 4, 'INV-2026-1004', DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 28 DAY), 10593.22, 1906.78, 0.00, 12500.00, 0.00, 12500.00, 'sent', 'Monthly Maintenance Retainer.'),
(5, 1, 5, 'INV-2026-1005', DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_SUB(CURDATE(), INTERVAL 30 DAY), 25000.00, 4500.00, 0.00, 29500.00, 29500.00, 0.00, 'paid', 'SEO & Performance Optimization.')
ON DUPLICATE KEY UPDATE `invoice_number` = VALUES(`invoice_number`);

-- 11. Invoice Items
INSERT INTO `invoice_items` (
  `id`, `invoice_id`, `description`, `hsn_sac`, `quantity`, `unit_price`, `tax_rate`, `tax_amount`, `line_total`
) VALUES
(1, 1, 'Custom Web Application Development — Core API architecture', '998314', 1.00, 85000.00, 18.00, 15300.00, 100300.00),
(2, 2, 'Cloud Infrastructure Setup & Migration — CI/CD pipeline', '998315', 1.00, 45000.00, 18.00, 8100.00, 53100.00),
(3, 3, 'UI/UX Design & Brand Prototyping — Responsive screens', '998311', 1.00, 38135.59, 18.00, 6864.41, 45000.00),
(4, 4, 'Monthly Retainer — IT Support & SLA (Half Month)', '998313', 0.50, 21186.44, 18.00, 1906.78, 12500.00),
(5, 5, 'Performance Optimization & Query Tuning', '998314', 1.00, 25000.00, 18.00, 4500.00, 29500.00)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- 12. Payments
INSERT INTO `payments` (
  `id`, `business_id`, `customer_id`, `payment_number`, 
  `payment_date`, `amount`, `method`, `reference`, `status`
) VALUES
(1, 1, 1, 'PAY-2026-0001', DATE_SUB(CURDATE(), INTERVAL 20 DAY), 71800.00, 'bank_transfer', 'NEFT-HDFC-9918234', 'completed'),
(2, 1, 2, 'PAY-2026-0002', DATE_SUB(CURDATE(), INTERVAL 12 DAY), 53100.00, 'upi', 'UPI-ZENITH-482019', 'completed'),
(3, 1, 5, 'PAY-2026-0003', DATE_SUB(CURDATE(), INTERVAL 35 DAY), 29500.00, 'bank_transfer', 'RTGS-ICICI-110294', 'completed')
ON DUPLICATE KEY UPDATE `payment_number` = VALUES(`payment_number`);

-- 13. Expense Categories
INSERT INTO `expense_categories` (`id`, `business_id`, `name`, `color`) VALUES
(1, 1, 'Office & Rent', '#0284c7'),
(2, 1, 'Cloud & Hosting Infrastructure', '#8b5cf6'),
(3, 1, 'Software Subscriptions & Tools', '#10b981'),
(4, 1, 'Travel & Client Meetings', '#f59e0b'),
(5, 1, 'Professional & Legal Fees', '#64748b')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 14. Expenses
INSERT INTO `expenses` (
  `id`, `business_id`, `category_id`, `description`, `amount`, `expense_date`, 
  `method`, `vendor`, `status`
) VALUES
(1, 1, 1, 'Office Space Lease (Andheri East)', 42000.00, DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'bank_transfer', 'TechPark Estates', 'active'),
(2, 1, 2, 'AWS Cloud Server Hosting & Bandwidth', 18450.00, DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'credit_card', 'Amazon Web Services', 'active'),
(3, 1, 3, 'GitHub & Google Workspace Licenses', 8200.00, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'credit_card', 'Google Cloud India', 'active'),
(4, 1, 4, 'Client Meeting & Commute Expenses', 3450.00, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'cash', 'Uber & Local Transit', 'active')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
