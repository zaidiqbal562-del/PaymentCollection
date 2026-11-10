
<!DOCTYPE html>
<html>
<head>
    <title>Edit Course</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 40px;
            background: #f5f5f5;
        }

        .container {
            max-width: 750px;
            margin: 0 auto;
        }

        .header {
            background: #222;
            color: white;
            padding: 20px 25px;
            border-radius: 6px 6px 0 0;
        }

        .header h2 {
            margin: 0;
        }

        .form-container {
            background: white;
            padding: 30px;
            border-radius: 0 0 6px 6px;
            border: 1px solid #ddd;
        }

        .form-group {
            margin-bottom: 22px;
        }

        label {
            display: block;
            margin-bottom: 9px;
            font-weight: bold;
            color: #333;
        }

        input, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 15px;
            font-family: Arial, sans-serif;
        }

        input:focus, textarea:focus {
            outline: none;
            border-color: #444;
        }

        textarea {
            min-height: 140px;
            resize: vertical;
        }

        .error {
            color: red;
            margin-bottom: 15px;
            background: #fff0f0;
            padding: 10px;
            border-radius: 5px;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        button, .cancel {
            padding: 11px 20px;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            border: none;
        }

        button {
            background: blue;
            color: white;
        }

        button:hover {
            background: darkblue;
        }

        .cancel {
            background: #666;
            color: white;
        }

        .cancel:hover {
            background: #444;
        }

        @media (max-width: 600px) {
            body {
                padding: 15px;
            }

            .form-container {
                padding: 20px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h2>Edit Course</h2>
    </div>

    <div class="form-container">

        @if($errors->any())
            <div class="error">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form action="{{ route('courses.update', $course->id) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label>Course Title</label>
                <input
                    type="text"
                    name="title"
                    placeholder="Enter course title"
                    value="{{ old('title', $course->title) }}"
                    required
                >
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea
                    name="description"
                    placeholder="Enter course description"
                    required
                >{{ old('description', $course->description) }}</textarea>
            </div>

            <div class="form-group">
                <label>Price</label>
                <input
                    type="number"
                    name="price"
                    step="0.01"
                    min="0"
                    placeholder="Enter course price"
                    value="{{ old('price', $course->price) }}"
                    required
                >
            </div>

            <div class="actions">
                <button type="submit">Update Course</button>

                <a href="{{ route('courses.index') }}" class="cancel">
                    Cancel
                </a>
            </div>

        </form>

    </div>

</div>

</body>
</html>
