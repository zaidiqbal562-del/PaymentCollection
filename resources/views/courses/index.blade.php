
<!DOCTYPE html>
<html>
<head>
    <title>Manage Courses</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { font-family: Arial; margin: 40px; background: #f5f5f5; }
        table { width: 100%; border-collapse: collapse; background: white; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background: #222; color: white; }
        a, button { padding: 8px 12px; cursor: pointer; }
        .add { background: green; color: white; text-decoration: none; }
        .edit { background: blue; color: white; text-decoration: none; }
        .delete { background: red; color: white; border: none; }
    </style>
</head>
<body>

<h2>Course Management</h2>
<div style="display: flex; justify-content: space-between; align-items: center;">
    

    <form action="{{ route('logout') }}" method="POST">
        @csrf
        <button type="submit">Logout</button>
    </form>
</div>
@if(auth()->user()->is_admin)
<a href="{{ route('courses.create') }}" class="add">Add New Course</a>
@endif

<br><br>

@if(session('success'))
    <p style="color:green">{{ session('success') }}</p>
@endif

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Title</th>
            <th>Description</th>
            <th>Price</th>
            <th>Purchase</th>
            @can('manage-courses')
                <th>Manage</th>
            @endcan
        </tr>
    </thead>

    <tbody>
        @forelse($courses as $course)
            <tr>
                <td>{{ $course->id }}</td>
                <td>{{ $course->title }}</td>
                <td>{{ $course->description }}</td>
                <td>{{ $course->price }}</td>
                <td>
                    @auth
                        @if($course->enrolled_by_current_user)
                            <span>Enrolled</span>
                        @else
                            <button type="button" class="buy-course" data-course-id="{{ $course->id }}">
                                Buy course
                            </button>
                        @endif
                    @else
                        <a href="{{ route('login') }}">Sign in to buy</a>
                    @endauth
                </td>
                @can('manage-courses')
                <td>
                    <a href="{{ route('courses.edit', $course->id) }}"
                       class="edit">Edit</a>

                    <form action="{{ route('courses.destroy', $course->id) }}"
                          method="POST" style="display:inline">
                        @csrf
                        @method('DELETE')

                        <button type="submit" class="delete"
                            onclick="return confirm('Are you sure you want to delete this course?')">
                            Delete
                        </button>
                    </form>
                </td>
                @endcan
            </tr>
        @empty
            <tr>
                <td colspan="{{ auth()->user()?->can('manage-courses') ? 6 : 5 }}">No courses found.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<p id="checkout-status" role="status" aria-live="polite"></p>

@auth
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    const checkoutStatus = document.getElementById('checkout-status');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    function showCheckoutStatus(message) {
        checkoutStatus.textContent = message;
    }

    async function readJson(response) {
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const error = new Error(data.message || 'Unable to continue checkout.');
            error.status = response.status;
            throw error;
        }

        return data;
    }

    async function waitForPayment(orderNumber, storageKey, button) {
        for (let attempt = 0; attempt < 30; attempt++) {
            const response = await fetch(`/orders/${encodeURIComponent(orderNumber)}/status`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await readJson(response);

            if (data.status === 'paid') {
                sessionStorage.removeItem(storageKey);
                showCheckoutStatus('Payment confirmed. You are enrolled in the course.');
                button.textContent = 'Enrolled';
                button.disabled = true;
                window.setTimeout(() => window.location.reload(), 1200);
                return;
            }

            if (data.status === 'failed') {
                sessionStorage.removeItem(storageKey);
                showCheckoutStatus('This order could not be completed. Please try again.');
                button.disabled = false;
                return;
            }

            await new Promise(resolve => window.setTimeout(resolve, 2000));
        }

        showCheckoutStatus('Payment is still processing. You can check again shortly.');
        button.disabled = false;
    }

    document.querySelectorAll('.buy-course').forEach(button => {
        button.addEventListener('click', async () => {
            const courseId = button.dataset.courseId;
            const storageKey = `payment-idempotency:${courseId}`;
            button.disabled = true;
            showCheckoutStatus('Creating secure payment order...');

            try {
                const idempotencyKey = sessionStorage.getItem(storageKey) || crypto.randomUUID();
                sessionStorage.setItem(storageKey, idempotencyKey);

                const orderResponse = await fetch('{{ route('orders.store') }}', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Idempotency-Key': idempotencyKey,
                    },
                    body: JSON.stringify({ course_id: Number(courseId) }),
                });
                const orderData = await readJson(orderResponse);
                const order = orderData.data;

                if (!window.Razorpay) {
                    throw new Error('Razorpay Checkout could not be loaded. Please refresh and try again.');
                }

                const checkout = new Razorpay({
                    key: order.key,
                    amount: order.amount,
                    currency: order.currency,
                    order_id: order.razorpay_order_id,
                    name: document.title,
                    description: 'Course enrollment',
                    handler: async payment => {
                        showCheckoutStatus('Verifying payment...');

                        try {
                            const verifyResponse = await fetch(`/orders/${encodeURIComponent(order.order_number)}/verify-payment`, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrfToken,
                                },
                                body: JSON.stringify(payment),
                            });
                            await readJson(verifyResponse);
                            await waitForPayment(order.order_number, storageKey, button);
                        } catch (error) {
                            showCheckoutStatus(error.message);
                            button.disabled = false;
                        }
                    },
                    modal: {
                        ondismiss: () => {
                            showCheckoutStatus('Checkout closed. Your order is ready if you want to retry.');
                            button.disabled = false;
                        },
                    },
                });

                checkout.open();
                showCheckoutStatus('Complete payment in the Razorpay window.');
            } catch (error) {
                if (error.status === 502) {
                    sessionStorage.removeItem(storageKey);
                }
                showCheckoutStatus(error.message);
                button.disabled = false;
            }
        });
    });
</script>
@endauth

</body>
</html>
