<?php
/** Read-only counter address slips. No postage purchase or fulfillment writes. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class WLP_Manual_Counter_Labels {
 public static function render( array $rows ): void {
  nocache_headers();
  header( 'Content-Type: text/html; charset=UTF-8' );
  header( 'X-Robots-Tag: noindex, nofollow' );
  header( 'Referrer-Policy: no-referrer' );
  $return = array_filter( array(
   WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_COMPANY ),
   WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_NAME ),
   WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_ADDR_1 ),
   WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_ADDR_2 ),
   trim( WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_CITY ) . ' ' . WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_PROV ) . '  ' . WLP_Settings::return_option( WLP_Settings::OPTION_RETURN_POSTAL ) ),
   'CANADA',
  ) );
  $services = array( '' => 'Confirm service at counter', 'DOM.XP' => 'Xpresspost', 'DOM.PC' => 'Priority', 'DOM.EP' => 'Expedited Parcel', 'DOM.RP' => 'Regular Parcel' );
  $options = array( 'yes' => 'Required', 'no' => 'Not requested', 'confirm' => 'Confirm at counter' );
  ?>
<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Manual counter labels | PeptideGo</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f0f2f4;color:#111;font-family:Arial,sans-serif}.toolbar{max-width:980px;margin:auto;padding:24px}h1{font-size:24px;margin:0 0 10px}p{line-height:1.5}.controls{display:flex;gap:12px;flex-wrap:wrap;align-items:center}button,select{font:inherit;padding:9px;border:1px solid #777;border-radius:5px;background:#fff}button{cursor:pointer}.print{background:#182c3d;color:white}label{display:inline-flex;gap:8px;align-items:center}.editors{display:grid;gap:10px;margin-top:16px}.editor{background:white;padding:12px;border:1px solid #ccc;border-radius:6px}.editor strong{display:block;margin-bottom:8px}.editor .controls{font-size:13px}.source{font-size:12px;color:#555;margin:8px 0 0}.sheet{display:contents}.labels{display:flex;flex-direction:column;align-items:center;gap:18px;padding:0 12px 24px}.slip{width:4in;height:6in;background:white;padding:.23in;border:1px dashed #999;display:flex;flex-direction:column;overflow-wrap:anywhere;break-inside:avoid}.heading{display:flex;justify-content:space-between;gap:10px;font-size:10px;font-weight:bold}.return{font-size:10px;line-height:1.18;margin:8px 0 15px}.to{font-size:10px;font-weight:bold;border-top:1px solid #888;padding-top:12px;margin-bottom:8px}.address{font-size:17px;font-weight:bold;line-height:1.2}.delivery{margin-top:auto;padding-top:10px;border-top:1px solid #888}.service{font-size:19px;font-weight:bold;margin-bottom:8px}.option{font-size:12px;line-height:1.35}.unpaid{font-size:8px;margin-top:9px;letter-spacing:.3px}.letter .labels{display:grid;grid-template-columns:repeat(2,3.75in);width:max-content;max-width:100%;gap:.25in;margin:auto}.letter .slip{width:3.75in;height:3in;padding:.12in}.letter .heading{font-size:7px}.letter .return{font-size:7px;line-height:1.05;margin:4px 0 5px}.letter .to{font-size:7px;padding-top:4px;margin-bottom:4px}.letter .address{font-size:11px;line-height:1.12}.letter .delivery{padding-top:4px}.letter .service{font-size:11px;margin-bottom:2px}.letter .option{font-size:8px;line-height:1.1}.letter .unpaid{font-size:6px;margin-top:3px}@media screen and (max-width:650px){.letter .labels{display:flex;width:auto}.toolbar{padding:16px}.slip{max-width:100%}}@media print{html,body{margin:0;background:white}.toolbar{display:none!important}.labels{display:block;margin:0;padding:0}.slip{border:0;break-after:page;width:4in;height:6in}.slip:last-child{break-after:auto}.letter .labels{display:block;width:auto;padding:0;margin:0}.letter .sheet{display:grid;grid-template-columns:repeat(2,3.75in);gap:.25in;break-after:page}.letter .sheet:last-child{break-after:auto}.letter .slip{border:1px dashed #aaa;break-after:auto}.sheet{display:contents}}
</style><style id="paper-size">@page{size:4in 6in;margin:0}</style></head><body>
<div class="toolbar"><h1>Manual counter labels</h1><p>Print an address slip, attach it to the matching parcel, and have the clerk create the paid shipping label. Leave room for their label. Printing does not buy postage, send email, or mark orders packed or shipped.</p>
<div class="controls"><label>Paper <select id="paper"><option value="rollo">Rollo / 4 × 6 inches</option><option value="letter">Letter / 6 slips per sheet</option></select></label><button type="button" class="print" id="print">Print address slips</button><a href="<?php echo esc_url( admin_url( 'admin.php?page=wlp-logistics' ) ); ?>">Back to Logistics</a></div><p class="source">Print at 100% / Actual Size with browser headers and footers off. Changes below apply to this printout only. Signature requests a signed handoff; Card for Pickup requests collection at the post office. The clerk must enter these options.</p>
<div class="editors">
<?php foreach ( $rows as $i => $row ) : ?>
<section class="editor"><strong>Order #<?php echo esc_html( (string) $row['number'] ); ?> · <?php echo $row['wholesale'] ? 'Wholesale' : 'Retail'; ?></strong><div class="controls">
<label>Service <select data-row="<?php echo (int) $i; ?>" data-field="service"><?php foreach ( $services as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['service'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
<?php foreach ( array( 'signature' => 'Signature', 'card' => 'Card for Pickup' ) as $field => $title ) : ?><label><?php echo esc_html( $title ); ?> <select data-row="<?php echo (int) $i; ?>" data-field="<?php echo esc_attr( $field ); ?>"><?php foreach ( $options as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row[ $field ], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
</div><p class="source">Service: <?php echo esc_html( $row['source'] ); ?>. Delivery options: saved customer choices where present; otherwise wholesale policy or Logistics defaults. Unspecified retail options are marked for confirmation.</p></section>
<?php endforeach; ?></div></div>
<main class="labels">
<?php foreach ( array_chunk( $rows, 6, true ) as $chunk ) : ?><div class="sheet"><?php foreach ( $chunk as $i => $row ) : $a = $row['shipping']; $address = array_filter( array( trim( $a['first_name'] . ' ' . $a['last_name'] ), $a['company'], $a['address_1'], $a['address_2'], trim( $a['city'] . ' ' . $a['state'] . '  ' . $a['postcode'] ), 'CA' === $a['country'] ? 'CANADA' : $a['country'] ) ); ?>
<article class="slip" id="slip-<?php echo (int) $i; ?>"><div class="heading"><span>RETURN TO</span><span>ORDER #<?php echo esc_html( (string) $row['number'] ); ?></span></div><div class="return"><?php foreach ( $return as $line ) : ?><div><?php echo esc_html( $line ); ?></div><?php endforeach; ?></div><div class="to">SHIP TO</div><div class="address"><?php foreach ( $address as $line ) : ?><div><?php echo esc_html( $line ); ?></div><?php endforeach; ?></div><div class="delivery"><div class="service" data-value="service"><?php echo esc_html( $services[ $row['service'] ] ?? $services[''] ); ?></div><div class="option">Signature: <strong data-value="signature"><?php echo esc_html( $options[ $row['signature'] ] ); ?></strong></div><div class="option">Card for Pickup: <strong data-value="card"><?php echo esc_html( $options[ $row['card'] ] ); ?></strong></div><div class="unpaid">ADDRESS SLIP ONLY · POSTAGE REQUIRED</div></div></article>
<?php endforeach; ?></div><?php endforeach; ?>
</main><script>
'use strict';
const paper=document.getElementById('paper');
paper.addEventListener('change',()=>{document.body.classList.toggle('letter',paper.value==='letter');document.getElementById('paper-size').textContent=paper.value==='letter'?'@page{size:letter;margin:.5in .375in}':'@page{size:4in 6in;margin:0}';});
document.querySelectorAll('select[data-row]').forEach(select=>select.addEventListener('change',()=>{document.querySelector('#slip-'+select.dataset.row+' [data-value="'+select.dataset.field+'"]').textContent=select.options[select.selectedIndex].text;}));
document.getElementById('print').addEventListener('click',()=>{const overflow=[...document.querySelectorAll('.slip')].some(s=>s.scrollHeight>s.clientHeight+2||s.scrollWidth>s.clientWidth+2);if(overflow){alert('An address is too long for this layout. Choose the 4 × 6 layout or correct the address before printing.');return;}window.print();});
</script></body></html>
<?php
 }
}
