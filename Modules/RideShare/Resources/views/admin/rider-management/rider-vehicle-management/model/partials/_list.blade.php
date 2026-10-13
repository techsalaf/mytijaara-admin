@php($listQuery = array_filter(request()->except(['status', 'page'])))
@php($exportQuery = array_filter(request()->only(['search', 'status'])))
@php($tabs = ['all' => translate('All'), 'active' => translate('messages.Active'), 'inactive' => translate('messages.Inactive')])

<div class="card">
    <ul class="nav nav-tabs flex-wrap tabs-inner border-0 nav--tabs px-3 pt-3">
        @foreach ($tabs as $tab => $label)
            <li class="nav-item">
                <a class="nav-link {{ $status === $tab ? 'active' : '' }}"
                    href="{{ route('admin.users.rider.vehicle.model.index', array_merge($listQuery, ['status' => $tab])) }}">
                    {{ $label }}
                    <span class="badge">{{ $counts[$tab] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card-header py-2 border-0">
        <div class="search--button-wrapper">
            @include('partials._table-head', [
                'title' => translate('Model list'),
                'subtitle' => translate('messages.Each model riders can choose from, and which brand it belongs to.'),
                'count' => $models->total(),
                'count_id' => 'itemCount',
            ])

            <form class="search-form w-340-lg">
                <div class="input-group input--group">
                    <input type="search" name="search" value="{{ request('search') }}" class="form-control h-40"
                        placeholder="{{ translate('messages.search here by Model Name') }}"
                        aria-label="{{ translate('messages.search here by Model Name') }}">
                    <input type="hidden" name="status" value="{{ $status }}">
                    <button type="submit" class="btn btn--primary h-40"><i class="tio-search"></i></button>
                </div>
            </form>

            @if (request('search'))
                <a class="btn btn--primary ml-2 list-reset-search"
                    href="{{ request()->fullUrlWithoutQuery(['search', 'page']) }}"><i class="tio-refresh"></i>
                    {{ translate('messages.Reset') }}</a>
            @endif

            <div class="hs-unfold mr-2">
                <a class="js-hs-unfold-invoker btn btn-sm btn-white text-title dropdown-toggle font-medium min-height-40"
                    href="javascript:;"
                    data-hs-unfold-options='{
                            "target": "#usersExportDropdown",
                            "type": "css-animation"
                        }'>
                    <i class="tio-download-to mr-1 text-title"></i> {{ translate('messages.Export') }}
                </a>

                <div id="usersExportDropdown"
                    class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-sm-right">
                    <span class="dropdown-header">{{ translate('messages.Download options') }}</span>
                    <a id="export-excel" class="dropdown-item"
                        href="{{ route('admin.users.rider.vehicle.model.export', array_merge($exportQuery, ['file' => 'excel'])) }}">
                        <img class="avatar avatar-xss avatar-4by3 mr-2"
                            src="{{ asset('public/assets/admin') }}/svg/components/excel.svg" alt="">
                        Excel
                    </a>
                    <a id="export-csv" class="dropdown-item"
                        href="{{ route('admin.users.rider.vehicle.model.export', array_merge($exportQuery, ['file' => 'csv'])) }}">
                        <img class="avatar avatar-xss avatar-4by3 mr-2"
                            src="{{ asset('public/assets/admin') }}/svg/components/placeholder-csv-format.svg" alt="">
                        CSV
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive datatable-custom">
            <table
                class="table table-hover table-borderless table-thead-bordered table-nowrap table-align-middle card-table table--wrap-head text--title">
                <thead class="thead-light">
                    <tr>
                        <th class="border-0">{{ translate('Model name') }}</th>
                        <th class="border-0">{{ translate('Brand name') }}</th>
                        <th class="border-0 col--numeric">{{ translate('messages.vehicles') }}</th>
                        <th class="border-0">{{ translate('messages.Added') }}</th>
                        <th class="border-0 text-center">{{ translate('messages.Status') }}</th>
                        <th class="border-0 text-center">{{ translate('messages.Action') }}</th>
                    </tr>
                </thead>

                <tbody id="set-rows">
                    @foreach ($models as $model)
                        @php($vehicleCount = $model->vehicles_count ?? 0)
                        <tr id="hide-row-{{ $model->id }}" class="record-row">
                            <td>
                                <a class="table-rest-info"
                                    href="{{ route('admin.users.rider.vehicle.model.edit', ['id' => $model->id]) }}"
                                    title="{{ $model->name }}">
                                    <img class="img--60 rounded onerror-image"
                                        data-onerror-image="{{ asset('public/assets/admin/img/160x160/img2.jpg') }}"
                                        src="{{ $model->image_full_url }}" alt="{{ $model->name }}">
                                    <div class="info max-w-200px">
                                        <div class="text--title line--limit-1">{{ $model->name }}</div>
                                        @if ($model->description)
                                            <span
                                                class="d-block fs-12 text-muted line--limit-1">{{ Str::limit($model->description, 40, '...') }}</span>
                                        @endif
                                        <div class="font-light">ID:{{ $model->id }}</div>
                                    </div>
                                </a>
                            </td>
                            <td>{{ $model->brand->name ?? '—' }}</td>
                            <td class="col--numeric">
                                <span class="badge badge-soft-{{ $vehicleCount ? 'success' : 'secondary' }}"
                                    title="{{ translate('Vehicles riders registered with this model') }}">{{ $vehicleCount }}</span>
                            </td>
                            <td>
                                <span
                                    title="{{ \App\CentralLogics\Helpers::time_date_format($model->created_at) }}">{{ \App\CentralLogics\Helpers::date_format($model->created_at) }}</span>
                            </td>
                            <td class="text-center">
                                <div class="status-toggle"
                                    data-status="{{ $model->is_active ? 1 : 0 }}">
                                    <label class="toggle-switch toggle-switch-sm"
                                        for="statusCheckbox{{ $model->id }}">
                                        <input type="checkbox" data-id="statusCheckbox{{ $model->id }}"
                                            data-type="status"
                                            data-image-on="{{ asset('/public/assets/admin/img/modal/basic_campaign_on.png') }}"
                                            data-image-off="{{ asset('/public/assets/admin/img/modal/basic_campaign_off.png') }}"
                                            data-title-on="{{ translate('By turning ON model!') }}"
                                            data-title-off="{{ translate('By turning OFF model!') }}"
                                            data-text-on="<p>{{ translate('Riders will be able to pick this model when registering a vehicle.') }}</p>"
                                            data-text-off="<p>{{ translate('Riders will no longer be able to pick this model when registering a vehicle.') }}</p>"
                                            @if ($status !== 'all') data-ajax-refresh="[data-ajax-region]" @endif
                                            class="toggle-switch-input dynamic-checkbox"
                                            id="statusCheckbox{{ $model->id }}" {{ $model->is_active ? 'checked' : '' }}>
                                        <span class="toggle-switch-label">
                                            <span class="toggle-switch-indicator"></span>
                                        </span>
                                    </label>
                                    <span class="status-toggle__text" aria-live="polite">
                                        {{ $model->is_active ? translate('messages.Active') : translate('messages.Inactive') }}
                                    </span>
                                </div>
                                <form
                                    action="{{ route('admin.users.rider.vehicle.model.status', ['id' => $model->id, 'status' => $model->is_active ? 0 : 1]) }}"
                                    method="get" id="statusCheckbox{{ $model->id }}_form">
                                    <input type="hidden" name="status" value="{{ $model->is_active ? 0 : 1 }}">
                                    <input type="hidden" name="id" value="{{ $model->id }}">
                                </form>
                            </td>
                            <td>
                                <div class="btn--container justify-content-center">
                                    <a class="btn action-btn action-btn--edit"
                                        href="{{ route('admin.users.rider.vehicle.model.edit', ['id' => $model->id]) }}"
                                        title="{{ translate('messages.Edit model') }}"><i class="tio-edit"></i>
                                    </a>
                                    <a class="btn action-btn action-btn--delete form-alert" href="javascript:"
                                        data-id="model-{{ $model->id }}"
                                        data-message="{{ $vehicleCount
                                            ? translate('messages.Vehicles using this model') . ': ' . $vehicleCount . '. ' . translate('messages.Deleting it removes the model from them. Continue?')
                                            : translate('Want to delete this model?') }}"
                                        title="{{ translate('messages.Delete model') }}"><i
                                            class="tio-delete-outlined"></i>
                                    </a>
                                    <form
                                        action="{{ route('admin.users.rider.vehicle.model.delete', ['id' => $model->id]) }}"
                                        method="post" id="model-{{ $model->id }}" data-ajax-form
                                        data-ajax-remove="closest:tr" data-ajax-refresh="[data-ajax-region]">
                                        @csrf @method('delete')
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($models->count() === 0)
        <div class="empty--data">
            <img src="{{ asset('/public/assets/admin/svg/illustrations/sorry.svg') }}" alt="">
            @if (request('search'))
                <h5>{{ translate('messages.No model matches your search.') }}</h5>
                <p class="text-muted font-size-sm">
                    {{ translate('Try a different spelling, or clear the search to see every model.') }}</p>
            @elseif ($status !== 'all')
                <h5>{{ translate('messages.No model on this tab.') }}</h5>
                <p class="text-muted font-size-sm">
                    {{ translate('Switch to all to see every model you have added.') }}</p>
            @else
                <h5>{{ translate('messages.No model added yet.') }}</h5>
                <p class="text-muted font-size-sm">
                    {{ translate('Add your first one with the form above, once you have a brand to file it under.') }}
                </p>
            @endif
        </div>
    @endif

    <div class="page-area px-4 pb-3">
        {!! $models->withQueryString()->links() !!}
    </div>
</div>
