@props(['label', 'name', 'value' => null, 'required' => false])
<label for="{{ $name }}" class="form-label">{{ $label }}</label>
<input id="{{ $name }}" name="{{ $name }}" value="{{ old($name, $value) }}" class="form-control" @required($required) {{ $attributes }}>
@error($name)<p class="form-error">{{ $message }}</p>@enderror
