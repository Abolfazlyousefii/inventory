<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class SellerCommissionSalesPreviewRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['seller_id'=>['nullable','integer','exists:users,id'],'date_from'=>['nullable','string','max:20'],'date_to'=>['nullable','string','max:20'],'invoice_number'=>['nullable','string','max:100'],'customer'=>['nullable','string','max:100'],'user_id'=>['nullable','integer','exists:users,id'],'document_number'=>['nullable','string','max:50'],'tab'=>['nullable','string','max:20']]; } }
