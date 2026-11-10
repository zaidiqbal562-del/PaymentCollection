We are integrating Razorpay into our Laravel + MySQL payment platform, designed to handle 2,000+ concurrent users.

**Completed so far:**
- Created Courses, Orders, Payments, Webhook Events, and Course Enrollments database tables.
- Added the `is_admin` column to Users and configured database-backed queues.
- Installed the Razorpay PHP SDK and configured the Razorpay service.
- Implemented the OrderController for secure order creation, idempotency, and payment signature verification.
- Migrated all required database changes successfully.

**Next plan:**
- Implement Razorpay webhook handling with signature verification and duplicate-event protection.
- Record payment attempts and verify captured payments against internal orders.
- Automatically enrol users after confirmed payment and dispatch notification jobs through Laravel queues.
- Complete frontend checkout integration, testing, and production-readiness improvements.