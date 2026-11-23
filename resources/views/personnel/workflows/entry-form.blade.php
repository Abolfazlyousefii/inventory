@php($prefix='personnel.'.($kind==='leave'?'leaves':'reports'))
<form class="hr-card" data-hr-save method="post" action="{{ $entry?route($prefix.'.update',$entry->id):route($prefix.'.store') }}" enctype="multipart/form-data">
@csrf @if($entry)@method('put')<input type="hidden" name="version" value="{{ $entry->version }}">@else<input type="hidden" name="submission_key" value="{{ old('submission_key',\Illuminate\Support\Str::uuid()) }}">@endif
<div class="hr-feedback" data-hr-feedback role="alert">@if($errors->any()){{ $errors->first() }}@endif</div>
@if($kind==='leave')
<div class="hr-note hr-route-note"><strong>@include('personnel.workflows.icon',['name'=>'team']) مسیر تأیید خودکار</strong><span>جایگزین هم‌واحد ← مسئول واحد ← مدیر داخلی ← مدیر اجرایی</span></div>
<div class="hr-form-grid">
<label>نوع مرخصی<select name="leave_type" required><option value="استحقاقی">استحقاقی</option><option value="استعلاجی">استعلاجی</option><option value="اضطراری">اضطراری</option><option value="ماموریت">مأموریت</option></select></label>
<label>نحوه ثبت<select name="leave_unit" data-hr-unit required><option value="روزانه">روزانه</option><option value="ساعتی">ساعتی</option></select></label>
<label>از تاریخ (شمسی)<div class="hr-date-field"><input name="start_date" value="{{ old('start_date') }}" placeholder="۱۴۰۵/۰۹/۰۱" data-hr-date required maxlength="10" dir="ltr"><button type="button" data-hr-calendar aria-label="انتخاب تاریخ شروع">@include('personnel.workflows.icon',['name'=>'calendar'])</button></div></label>
<label>تا تاریخ (شمسی)<div class="hr-date-field"><input name="end_date" value="{{ old('end_date') }}" placeholder="۱۴۰۵/۰۹/۰۱" data-hr-date required maxlength="10" dir="ltr"><button type="button" data-hr-calendar aria-label="انتخاب تاریخ پایان">@include('personnel.workflows.icon',['name'=>'calendar'])</button></div></label>
<label data-hr-hourly hidden>از ساعت<div class="hr-time-field" data-hr-time><input type="hidden" name="start_time" value="{{ old('start_time','08:00') }}"><select data-hr-clock="hour" aria-label="ساعت شروع">@foreach(range(0,23) as $hour)<option value="{{ sprintf('%02d',$hour) }}">{{ sprintf('%02d',$hour) }}</option>@endforeach</select><span>:</span><select data-hr-clock="minute" aria-label="دقیقه شروع">@foreach(range(0,59) as $minute)<option value="{{ sprintf('%02d',$minute) }}">{{ sprintf('%02d',$minute) }}</option>@endforeach</select><small>۲۴ ساعته</small></div></label>
<label data-hr-hourly hidden>تا ساعت<div class="hr-time-field" data-hr-time><input type="hidden" name="end_time" value="{{ old('end_time','12:00') }}"><select data-hr-clock="hour" aria-label="ساعت پایان">@foreach(range(0,23) as $hour)<option value="{{ sprintf('%02d',$hour) }}">{{ sprintf('%02d',$hour) }}</option>@endforeach</select><span>:</span><select data-hr-clock="minute" aria-label="دقیقه پایان">@foreach(range(0,59) as $minute)<option value="{{ sprintf('%02d',$minute) }}">{{ sprintf('%02d',$minute) }}</option>@endforeach</select><small>۲۴ ساعته</small></div></label>
<label class="hr-span">جایگزین هم‌واحد<select name="substitute_user_id" @required(filled(auth()->user()->department))><option value="">انتخاب جایگزین</option>@foreach($substitutes as $person)<option value="{{ $person->id }}" @selected((string)old('substitute_user_id')===(string)$person->id)>{{ $person->name }}</option>@endforeach</select><small>جایگزین باید فعال و دارای دسترسی مرخصی باشد.</small></label>
<label class="hr-span">علت مرخصی <small>(اختیاری)</small><textarea name="reason" rows="3" maxlength="4000">{{ old('reason') }}</textarea></label>
</div><p class="hr-help">مرخصی روزانه حداقل ۴۸ ساعت زودتر ثبت شود. اضطراری: فقط ساعتی، حداکثر ۴ ساعت در روز و ۳ درخواست در ماه شمسی.</p>
@else
<div class="hr-form-grid">
<label class="hr-span">عنوان <small>(اختیاری)</small><input name="title" maxlength="255" value="{{ old('title',$entry?->title) }}" placeholder="مثلاً پیگیری فروش و مشتریان"></label>
<label class="hr-span">شرح فعالیت‌ها<textarea name="description" required rows="8" maxlength="30000" placeholder="کارهای انجام‌شده، نتیجه و موارد نیازمند پیگیری…">{{ old('description',$entry?->description) }}</textarea></label>
<label>تماس‌های موفق <small>(اختیاری)</small><input type="number" min="0" max="100000" name="successful_calls" value="{{ old('successful_calls',$entry?->payload['successful_calls']??0) }}"></label>
<label>تماس‌های ناموفق <small>(اختیاری)</small><input type="number" min="0" max="100000" name="unsuccessful_calls" value="{{ old('unsuccessful_calls',$entry?->payload['unsuccessful_calls']??0) }}"></label>
<label class="hr-span hr-upload">@include('personnel.workflows.icon',['name'=>'download']) پیوست‌ها <small>(اختیاری؛ شامل فایل صوتی)</small><input type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.doc,.docx,.xls,.xlsx,.mp3,.wav,.ogg,.webm,.m4a,.mp4"><small>حداکثر ۵ فایل، هر فایل ۱۰ مگابایت؛ پیوست‌ها خصوصی هستند.</small></label>
@if($entry)@foreach($entry->attachments as $file)<a href="{{ route('personnel.attachments.download',$file->id) }}">{{ $file->name }}</a>@endforeach @endif
</div><p class="hr-help">گزارش پیش‌نویس فقط برای خودتان قابل مشاهده است. ارسال روزانه یک گزارش مجاز است، مگر دسترسی ویژه داشته باشید.</p>
@endif
<footer class="hr-form-actions">@if($kind==='report')<button class="hr-button hr-secondary" type="submit" name="action" value="draft">ذخیره پیش‌نویس</button><button class="hr-button" type="submit" name="action" value="submit">ارسال گزارش کار</button>@else<button class="hr-button" type="submit">ثبت درخواست مرخصی</button>@endif</footer>
</form>
