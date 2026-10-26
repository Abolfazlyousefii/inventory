const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const mathContext={module:{exports:{}}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/js/sales-discount-math.js'),'utf8'),mathContext);
const math=mathContext.module.exports;

function composer() {
    class Element {
        constructor(){this.value='';this.textContent='';this.children=[];this.events={};this.classList={toggle(){}};}
        addEventListener(name,callback){this.events[name]=callback;}
        click(){this.events.click?.();}
        append(...children){this.children.push(...children);}
        replaceChildren(){this.children=[];}
        setCustomValidity(){}
        removeAttribute(){}
    }
    const ids=Object.fromEntries(['financeEditForm','financePaymentComposer','financeStagedPayments','paymentRows','paymentGuide','cashTotalLabel','chequeTotalLabel','allTotalLabel','paymentModalError','paymentTypeInput','cashFields','chequeFields','cashAmountInput','cashPaidAtInput','cashBankNameInput','cashNoteInput','chequeAmountInput','chequeNumberInput','chequeBankNameInput','chequeReceivedAtInput','chequeDueDateInput','chequeStatusInput','chequeNoteInput','addPaymentRow','savePaymentBtn'].map(id=>[id,new Element()]));
    ids.financeStagedPayments.textContent='[]';
    ids.financePaymentComposer.querySelectorAll=()=>Object.entries(ids).filter(([key])=>/Input$/.test(key)).map(([,value])=>value);
    ids.financePaymentComposer.querySelector=()=>new Element();
    const window={SalesDiscountMath:math};
    const document={getElementById:id=>ids[id],createElement:()=>new Element(),dispatchEvent(){}};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/js/finance-review-payments.js'),'utf8'),{window,document,bootstrap:{Modal:{getOrCreateInstance:()=>({show(){},hide(){}})}},Event:class{},Intl,Date});
    return {ids,rows:()=>JSON.parse(JSON.stringify(window.FinanceReviewPayments.rows()))};
}

test('Persian amounts and dates survive add/edit without adding a second payment',()=>{
    const {ids,rows}=composer();
    ids.addPaymentRow.click();
    assert.match(ids.cashPaidAtInput.value,/^\d{4}\/\d{2}\/\d{2}$/);
    ids.cashAmountInput.value='۱٬۰۰۰٬۰۰۰'; ids.cashPaidAtInput.value='۱۴۰۵/۰۸/۰۴';
    ids.savePaymentBtn.click();
    ids.savePaymentBtn.click();
    assert.equal(rows().length,1); assert.equal(rows()[0].amount,1000000); assert.equal(rows()[0].paid_at,'2026-10-26');
    ids.paymentRows.children[0].children[1].children[0].click();
    ids.cashAmountInput.value='۲٬۰۰۰٬۰۰۰'; ids.savePaymentBtn.click();
    assert.equal(rows().length,1); assert.equal(rows()[0].amount,2000000);
    ids.paymentRows.children[0].children[1].children[1].click();
    assert.equal(rows().length,0);
});

test('invalid Jalali dates and negative amounts are rejected before staging',()=>{
    const {ids,rows}=composer(); ids.addPaymentRow.click();
    ids.cashAmountInput.value='1000'; ids.cashPaidAtInput.value='۱۴۰۵/۱۲/۳۰'; ids.savePaymentBtn.click(); assert.equal(rows().length,0);
    ids.cashPaidAtInput.value='۱۴۰۵/۰۸/۰۴'; ids.cashAmountInput.value='-1000'; ids.savePaymentBtn.click(); assert.equal(rows().length,0);
});

test('cheque staging preserves received date due date bank and serial using canonical fields',()=>{
    const {ids,rows}=composer(); ids.addPaymentRow.click(); ids.paymentTypeInput.value='cheque';
    ids.chequeAmountInput.value='۲۵۰۰۰۰۰'; ids.chequeReceivedAtInput.value='۱۴۰۵/۰۸/۰۴'; ids.chequeDueDateInput.value='۱۴۰۵/۰۹/۰۴'; ids.chequeBankNameInput.value='بانک تست'; ids.chequeNumberInput.value='123';
    ids.savePaymentBtn.click();
    assert.equal(rows().length,1); assert.equal(rows()[0].cheque_received_at,'2026-10-26'); assert.equal(rows()[0].cheque_due_date,'2026-11-25'); assert.equal(rows()[0].cheque_bank_name,'بانک تست'); assert.equal(rows()[0].cheque_number,'123');
});
