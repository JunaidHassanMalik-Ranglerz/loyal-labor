<script src="{{ asset('global/js/jquery-3.7.1.min.js') }}"></script>
<script src="{{ asset('backend/js/popper.min.js') }}"></script>
<script src="{{ asset('backend/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('backend/js/jquery.nicescroll.min.js') }}"></script>
<script src="{{ asset('backend/js/moment.min.js') }}"></script>
<script src="{{ asset_ver('backend/js/stisla.js') }}"></script>
<script src="{{ asset_ver('backend/js/scripts.js') }}"></script>
<script src="{{ asset('backend/js/select2.min.js') }}"></script>
<script src="{{ asset('backend/js/tagify.js') }}"></script>
<script src="{{ asset('backend/js/bootstrap-toggle.jquery.min.js') }}"></script>
<script src="{{ asset('backend/js/fontawesome-iconpicker.min.js') }}"></script>
<script src="{{ asset('backend/js/bootstrap-datepicker.min.js') }}"></script>
<script src="{{ asset('backend/clockpicker/dist/bootstrap-clockpicker.js') }}"></script>
<script src="{{ asset('backend/datetimepicker/jquery.datetimepicker.js') }}"></script>
<script src="{{ asset('backend/js/iziToast.min.js') }}"></script>
<script src="{{ asset_ver('backend/js/toast-common.js') }}"></script>
<script src="{{ asset('backend/tinymce/js/tinymce/tinymce.min.js') }}" defer></script>
<script src="{{ asset('backend/js/jquery.uploadPreview.min.js') }}"></script>
<script src="{{ asset('website/js/Font-Awesome.js') }}"></script>
<script src="{{ asset_ver('backend/js/custom.js') }}"></script>
<script src="{{ asset_ver('js/admin-sidebar-custom.js') }}"></script>
<script src="{{ asset_ver('backend/js/datatables.min.js') }}"></script>
<script src="{{ asset_ver('backend/js/datatable-common.js') }}"></script>
@include('admin.partials.password-toggle')
@include('admin.partials.subscription-payment-notifications-scripts')

<script>
    @session('message')
    var type = "{{ Session::get('alert-type', 'info') }}"
    switch (type) {
        case 'info':
            iziToast.info({
                message: "{{ $value }}",
                position: 'topRight'
            });
            break;
        case 'success':
            iziToast.success({
                message: "{{ $value }}",
                position: 'topRight'
            });
            break;
        case 'warning':
            iziToast.warning({
                message: "{{ $value }}",
                position: 'topRight'
            });
            break;
        case 'error':
            iziToast.error({
                message: "{{ $value }}",
                position: 'topRight'
            });
            break;
    }
    @endsession
</script>

@if ($errors->any())
    @foreach ($errors->all() as $error)
        <script>
            iziToast.error({
                message: '{{ $error }}',
                position: 'topRight'
            });
        </script>
    @endforeach
@endif

<script>
    $(document).ready(function() {
        // Delegated binding so this also works for rows rendered later
        // (e.g. by server-side DataTables ajax draws).
        $(document).on('click', '.deleteForm', function() {
            const url = $(this).data('url');
            $('#deleteForm').attr('action', url);

            $('#deleteModal').modal('show');
        })

        $("[name='name'], [name='title']").on('input', function() {
            $("[name='slug']").val(convertToSlug($(this).val()));
        })
    })

    function convertToSlug(text = '') {
        return text.toString().toLowerCase().replace(/ /g, '-').replace(/[^\w-]+/g, '');
    }

    function prevImage(inputId, previewId, labelId) {
        $.uploadPreview({
            input_field: "#" + inputId,
            preview_box: "#" + previewId,
            label_field: "#" + labelId,
            label_default: "{{ __('Choose Image') }}",
            label_selected: "{{ __('Change Image') }}",
            no_label: false,
            success_callback: null
        });
    }

    function handleStatus(route) {
        $.ajax({
            url: route,
            type: 'post',
            headers: {
                'Accept': 'application/json'
            },
            success: function(res) {
                iziToast.success({
                    message: res.message,
                    position: 'topRight'
                });
            },
            error: function(err) {
                handleFetchError(err)
            }
        })
    }

    function handleFetchError(err) {
        if (err.status == 500) {
            iziToast.error({
                message: 'Something went wrong!',
                position: 'topRight'
            });
        } else {
            iziToast.error({
                message: err.responseJSON.message,
                position: 'topRight'
            });
        }
    }
</script>
