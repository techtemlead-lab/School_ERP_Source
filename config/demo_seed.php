<?php
declare(strict_types=1);
function demo_ident(string $name): string {
 if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$name))throw new RuntimeException('Invalid SQL identifier');
 return chr(96).$name.chr(96);
}
function demo_sql_file(PDO $pdo,string $path,bool $allowDuplicates=false): void {
 $sql=preg_replace('/^--.*$/m','',file_get_contents($path));
 foreach(preg_split('/;\s*(?:\n|$)/',$sql) as $statement){
  if(trim($statement)==='')continue;
  try{$pdo->exec($statement);}catch(PDOException $e){if(!$allowDuplicates||!in_array($e->errorInfo[1]??0,[1060,1061],true))throw $e;}
 }
}
function demo_insert(PDO $pdo,string $table,array $data): int {
 $columns=$GLOBALS['demo_seed_columns'][$table]??= $pdo->query('SHOW COLUMNS FROM '.demo_ident($table))->fetchAll(PDO::FETCH_COLUMN);
 $data=array_intersect_key($data,array_flip($columns));
 $sql='INSERT INTO '.demo_ident($table).' ('.implode(',',array_map('demo_ident',array_keys($data))).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')';
 try { ($GLOBALS['demo_seed_statements'][$sql]??=$pdo->prepare($sql))->execute(array_values($data)); } catch(PDOException $e) { throw new RuntimeException('Seed table '.$table.': '.$e->getMessage(),0,$e); } return(int)$pdo->lastInsertId();
}
function demo_seed_time(string $expression): int {return strtotime($expression,$GLOBALS['demo_seed_anchor']??time());}
function demo_seed_date(string $format,?int $timestamp=null): string {return date($format,$timestamp??$GLOBALS['demo_seed_anchor']??time());}
function demo_seed_database(PDO $pdo,?int $anchor=null): void {
 $GLOBALS['demo_seed_anchor']=$anchor??time();$GLOBALS['demo_seed_columns']=[];$GLOBALS['demo_seed_statements']=[];
 $root=dirname(__DIR__);demo_sql_file($pdo,$root.'/sql/schema.sql');demo_sql_file($pdo,$root.'/sql/full_sync.sql',true);demo_sql_file($pdo,$root.'/sql/demo_modules.sql');
 $pdo->exec("SET time_zone='+06:00'");
 foreach(['students','employees'] as $table){
  $cols=$pdo->query('SHOW COLUMNS FROM '.demo_ident($table))->fetchAll(PDO::FETCH_COLUMN);
  foreach(['rfid_card_no'=>'VARCHAR(100) NULL','tracking_id'=>'VARCHAR(100) NULL','device_serial'=>'VARCHAR(100) NULL','password'=>'VARCHAR(255) NULL','must_change_password'=>'TINYINT NOT NULL DEFAULT 0','branch_id'=>'INT NULL'] as $col=>$type)if(!in_array($col,$cols,true))$pdo->exec('ALTER TABLE '.demo_ident($table).' ADD COLUMN '.demo_ident($col).' '.$type);
 }
 require_once __DIR__.'/lesson_plan_helpers.php';lp_ensure_tables($pdo);
 require_once __DIR__.'/scholarship_application_helpers.php';sa_ensure_tables($pdo);
 require_once __DIR__.'/teacher_question_helpers.php';tq_ensure_tables($pdo);
 require_once __DIR__.'/academic_update_schema.php';erp_academic_update_schema($pdo);
 require_once __DIR__.'/branch_scope.php';branch_ensure_column($pdo,'academic_sections');
 $pdo->beginTransaction();
 $hash=password_hash('DemoAccess!2026',PASSWORD_DEFAULT);
 demo_insert($pdo,'system_users',['id'=>1,'branch_id'=>1,'user_type'=>'Demo','username'=>'demo_staff','email'=>'staff@shapla.example.invalid','password'=>$hash,'status'=>'Active']);$pdo->exec('DELETE FROM admin');demo_insert($pdo,'admin',['username'=>'demo_admin','password'=>$hash]);
 $pdo->prepare("UPDATE institute_settings SET institute_name=?,email=?,phone_number=?,slogan=?,short_description=?,why_choose_institute=?,website=?,logo_path=?,banner_path=?,eiin_no=? WHERE id=1")->execute([
 'Shapla Model School & College','office@shapla.example.invalid','01XXXXXXXXX','শিক্ষা, সততা ও আগামী দিনের স্বপ্ন',
 'শাপলা মডেল স্কুল অ্যান্ড কলেজ একটি সম্পূর্ণ কাল্পনিক ডেমো প্রতিষ্ঠান। শিক্ষার্থী, শিক্ষক ও অভিভাবকের তথ্য শুধু ERP পরীক্ষার জন্য তৈরি করা হয়েছে।',
 'সহযোগিতামূলক শিক্ষা, নিয়মিত মূল্যায়ন এবং সবার জন্য আনন্দময় শেখার পরিবেশ।','demo.schoolmng.techteamlead.com','assets/erp-mark.svg','','DEMO']);
 $pdo->prepare("UPDATE branches SET branch_name=?,address=?,city=?,zip_code=?,phone=?,email=? WHERE id=1")->execute(['Shapla Main Campus','Demo Campus, Rangpur, Bangladesh (fictional)','Rangpur','5400','01XXXXXXXXX','office@shapla.example.invalid']);
 demo_insert($pdo,'academic_sessions',['id'=>1,'session_name'=>demo_seed_date('Y'),'short_code'=>demo_seed_date('y'),'is_current_session'=>1,'is_fiscal_year'=>1,'start_month'=>'January','end_month'=>'December']);
 demo_insert($pdo,'academic_mediums',['id'=>1,'medium_name'=>'Bangla Version','medium_code'=>'BN']);
 demo_insert($pdo,'academic_shifts',['id'=>1,'medium_id'=>1,'shift_name'=>'Morning','start_time'=>'08:00:00','end_time'=>'13:00:00']);
 demo_insert($pdo,'academic_groups',['id'=>1,'group_name'=>'Science']);
 for($class=1;$class<=5;$class++){
  demo_insert($pdo,'academic_classes',['id'=>$class,'medium_id'=>1,'class_name'=>'Class '.($class+5),'class_code'=>(string)($class+5),'rank'=>$class,'has_group'=>$class>=4?1:0]);
  demo_insert($pdo,'academic_sections',['id'=>$class,'class_id'=>$class,'shift_id'=>1,'section_name'=>'Shapla','branch_id'=>1]);
  foreach(['Bangla','English','Mathematics'] as $i=>$subject)demo_insert($pdo,'academic_subjects',['id'=>($class-1)*3+$i+1,'class_id'=>$class,'subject_name'=>$subject,'subject_code'=>(string)(101+$i),'full_marks'=>100]);
 }
 demo_insert($pdo,'designations',['id'=>1,'title'=>'Assistant Teacher']);demo_insert($pdo,'employee_types',['id'=>1,'name'=>'Teacher']);
 $teachers=['Nusrat Jahan','Rafiqul Islam','Farhana Akter','Arif Hossain','Sadia Rahman','Imran Ahmed','Mousumi Das','Tanvir Hasan','Sumaiya Kabir','Shahidul Alam','Rubina Yasmin','Mehedi Hasan','Sharmin Sultana','Rashed Chowdhury','Dipali Sen','Kamrul Haque','Ishrat Jahan','Saiful Karim','Fariha Noor','Anirban Roy','Afroza Begum','Shahriar Khan','Tania Sarker','Mizanur Rahman'];
 foreach($teachers as $i=>$name)demo_insert($pdo,'employees',['id'=>$i+1,'type'=>'Teachers','employee_type_id'=>1,'designation_id'=>1,'employee_id'=>sprintf('DEMO-T%03d',$i+1),'name'=>$name,'mobile'=>'01XXXXXXXXX','email'=>'teacher'.($i+1).'@shapla.example.invalid','joining_date'=>demo_seed_date('Y').'-01-01','gender'=>$i%2?'Male':'Female','present_address'=>'Fictional demo address, Rangpur','permanent_address'=>'Fictional demo address, Bangladesh','photo_path'=>'assets/demo-avatar.svg','branch_id'=>1,'password'=>$hash,'must_change_password'=>0]);
 $girls=['Ayesha','Nabila','Mim','Sanjida','Tasnim','Anika','Raisa','Priyanka','Sumaiya','Fariha','Nusrat','Sadia'];$boys=['Hasan','Rakib','Sakib','Mahin','Fahim','Nayeem','Rohan','Arnab','Imran','Shahriar','Mehedi','Tanvir'];$family=['Rahman','Islam','Ahmed','Hossain','Sultana','Das','Karim','Sarker','Kabir','Roy'];
 $girlsBn=['আয়েশা','নাবিলা','মিম','সানজিদা','তাসনিম','অনিকা','রাইসা','প্রিয়াঙ্কা','সুমাইয়া','ফারিহা','নুসরাত','সাদিয়া'];$boysBn=['হাসান','রাকিব','সাকিব','মাহিন','ফাহিম','নাঈম','রোহান','অর্ণব','ইমরান','শাহরিয়ার','মেহেদী','তানভীর'];$familyBn=['রহমান','ইসলাম','আহমেদ','হোসেন','সুলতানা','দাস','করিম','সরকার','কবির','রায়'];
 for($n=1;$n<=240;$n++){
  $class=($n-1)%5+1;$female=$n%2===1;$firstIndex=intdiv($n-1,2)%12;$familyIndex=intdiv($n-1,24)%10;$name=($female?$girls:$boys)[$firstIndex].' '.$family[$familyIndex];$nameBn=($female?$girlsBn:$boysBn)[$firstIndex].' '.$familyBn[$familyIndex];
  demo_insert($pdo,'students',['id'=>$n,'session_id'=>1,'shift_id'=>1,'class_id'=>$class,'section_id'=>$class,'student_id'=>sprintf('DEMO-S%03d',$n),'roll_no'=>(string)(intdiv($n-1,5)+1),'name_en'=>$name,'name_bn'=>$nameBn,'gender'=>$female?'Female':'Male','blood_group'=>['A+','B+','O+','AB+'][$n%4],'religion'=>$n%7?'Islam':'Hinduism','nationality'=>'Bangladeshi','dob'=>demo_seed_date('Y',demo_seed_time('-13 years')).'-'.sprintf('%02d',($n%12)+1).'-'.sprintf('%02d',($n%27)+1),'mobile'=>'01XXXXXXXXX','email'=>'student'.$n.'@shapla.example.invalid','present_address'=>'Fictional demo neighbourhood, Rangpur','permanent_address'=>'Fictional demo address, Bangladesh','father_name'=>'Demo guardian '.$n,'mother_name'=>'Demo parent '.$n,'guardian_name'=>'Demo guardian '.$n,'guardian_phone'=>'01XXXXXXXXX','guardian_type'=>'Father','photo_path'=>'assets/demo-avatar.svg','branch_id'=>1,'group_id'=>$class>=4?1:null,'password'=>$hash,'must_change_password'=>0]);
 }
 foreach(['notices','sliders','gallery_items'] as $table)$pdo->exec('DELETE FROM '.demo_ident($table));
 foreach(['ডেমো বিদ্যালয়ে আপনাকে স্বাগতম — সব তথ্য কাল্পনিক','বার্ষিক বিজ্ঞান মেলা: সৃজনশীল কাজের প্রদর্শনী','অভিভাবক সভা ও একাডেমিক অগ্রগতি পর্যালোচনা'] as $i=>$title)demo_insert($pdo,'notices',['title'=>$title,'notice_date'=>demo_seed_date('Y-m-d',demo_seed_time('-'.$i.' days')),'branch_id'=>1]);
 demo_insert($pdo,'sliders',['title'=>'শেখার আনন্দ, আগামী দিনের প্রস্তুতি','subtitle'=>'শাপলা মডেল স্কুল অ্যান্ড কলেজ — School ERP-এর কাল্পনিক বাংলাদেশি ডেমো বিদ্যালয়','image_path'=>'assets/demo-school.svg','button_text'=>'ভর্তির আবেদন দেখুন','button_link'=>'admission_form.php','sort_order'=>1]);
 foreach(['আমাদের কাল্পনিক ক্যাম্পাস','বিজ্ঞান ও সৃজনশীলতার চর্চা','আনন্দময় শ্রেণিকক্ষ','সহশিক্ষা কার্যক্রম'] as $i=>$caption)demo_insert($pdo,'gallery_items',['caption'=>$caption,'image_path'=>'assets/demo-school.svg','sort_order'=>$i+1]);
 for($day=0;$day<30;$day++){
  $date=demo_seed_date('Y-m-d',demo_seed_time('-'.$day.' days'));
  for($n=1;$n<=24;$n++)demo_insert($pdo,'employee_daily_attendance',['employee_id'=>$n,'attendance_date'=>$date,'status'=>'Present','marked_by'=>'demo_admin','check_in_time'=>$date.' 07:45:00','source'=>'manual']);
  for($n=1;$n<=240;$n++)demo_insert($pdo,'student_daily_attendance',['student_id'=>$n,'class_id'=>($n-1)%5+1,'section_id'=>($n-1)%5+1,'shift_id'=>1,'attendance_date'=>$date,'status'=>($n+$day)%11?'Present':'Absent','marked_by'=>'demo_admin','check_in_time'=>$date.' 08:05:00']);
 }
 for($class=1;$class<=5;$class++){
  foreach([1,2,3] as $part){
   $subject=($class-1)*3+$part;
   demo_insert($pdo,'routines',['class_id'=>$class,'shift_id'=>1,'section_id'=>$class,'routine_days'=>'Sunday,Monday,Tuesday,Wednesday,Thursday','start_time'=>sprintf('%02d:00:00',7+$part),'end_time'=>sprintf('%02d:45:00',7+$part),'subject_id'=>$subject,'teacher_id'=>($class-1)*3+$part,'branch_id'=>1]);
   demo_insert($pdo,'teacher_subject_assignments',['employee_id'=>($class-1)*3+$part,'session_id'=>1,'class_id'=>$class,'section_id'=>$class,'subject_id'=>$subject]);
  }
  demo_insert($pdo,'elearning_homework',['class_id'=>$class,'section_id'=>$class,'subject_id'=>($class-1)*3+1,'teacher_id'=>($class-1)*3+1,'title'=>'Bangla: reading and creative writing','description'=>'Fictional demo exercise: read a short story and write a paragraph in your own words.','deadline'=>demo_seed_date('Y-m-d H:i:s',demo_seed_time('+3 days'))]);
 }
 demo_insert($pdo,'finance_account_heads',['id'=>1,'ac_code'=>'F-001','ac_head'=>'Monthly tuition','ac_type'=>'Academic','has_child'=>'No']);
 for($n=1;$n<=240;$n++){
  for($offset=0;$offset<6;$offset++){ $month=((int)demo_seed_date('n')-$offset+11)%12+1;
  demo_insert($pdo,'finance_student_monthly_fees',['student_id'=>$n,'session_id'=>1,'class_id'=>($n-1)%5+1,'month'=>$month,'ac_head_id'=>1,'amount'=>900]);
  if($n%4)demo_insert($pdo,'finance_fee_collections',['student_id'=>$n,'session_id'=>1,'period_type'=>'Monthly','month'=>$month,'ac_head_id'=>1,'amount'=>700,'payment_method'=>'Cash','receipt_no'=>'DEMO-'.$month.'-'.$n,'collected_at'=>demo_seed_date('Y-m-d H:i:s',demo_seed_time('-'.$offset.' months')),'collected_by'=>'demo_admin']);
  }
 }

 demo_insert($pdo,'exam_terms',['id'=>1,'session_id'=>1,'term_name'=>'Half-yearly assessment','rank'=>1,'website_result_publish'=>1]);
 demo_insert($pdo,'exam_parts',['id'=>1,'session_id'=>1,'part_name'=>'Written examination','part_short_name'=>'Written','rank'=>1]);
 foreach([['A+',5,80,100],['A',4,70,79],['A-',3.5,60,69],['B',3,50,59],['C',2,40,49],['D',1,33,39],['F',0,0,32]] as $grade)
  demo_insert($pdo,'exam_grade_points',['session_id'=>1,'grade_letter'=>$grade[0],'gpa'=>$grade[1],'mark_from'=>$grade[2],'mark_to'=>$grade[3],'remarks'=>'Fictional demo grade']);
 for($class=1;$class<=5;$class++){
  demo_insert($pdo,'class_teachers',['employee_id'=>($class-1)*3+1,'class_id'=>$class,'section_id'=>$class]);
  for($part=1;$part<=3;$part++)demo_insert($pdo,'exam_assign_subjects',['session_id'=>1,'term_id'=>1,'part_id'=>1,'class_id'=>$class,'subject_id'=>($class-1)*3+$part]);
 }
 for($n=1;$n<=240;$n++)for($part=1;$part<=3;$part++)
  demo_insert($pdo,'exam_marks',['session_id'=>1,'term_id'=>1,'part_id'=>1,'class_id'=>($n-1)%5+1,'shift_id'=>1,'section_id'=>($n-1)%5+1,'student_id'=>$n,'subject_id'=>((($n-1)%5)*3)+$part,'marks_obtained'=>52+(($n*13+$part*7)%47)]);
 foreach([['Science lab','Explore ideas through practical activities.'],['Library','Read, research and discover new interests.'],['Sports and clubs','Build confidence through teamwork and creativity.']] as $i=>$item)
  demo_insert($pdo,'website_facilities',['title'=>$item[0],'title_en'=>$item[0],'description'=>$item[1],'description_en'=>$item[1],'image_path'=>'assets/demo-school.svg','sort_order'=>$i]);
 demo_insert($pdo,'website_events',['title'=>'Demo science and innovation fair','event_date'=>demo_seed_date('Y-m-d',demo_seed_time('+14 days')),'image_path'=>'assets/demo-school.svg','location'=>'Fictional Shapla campus, Rangpur','description'=>'A fictional school event for exploring the public demo.']);
 demo_insert($pdo,'website_why_choose',['title'=>'Learning with care','image_path'=>'assets/demo-school.svg','description'=>'Experienced fictional teachers, creative activities and clear communication.']);
 demo_insert($pdo,'message_corner',['name'=>'Nusrat Jahan','designation'=>'Principal (fictional)','message'=>'Welcome to Shapla Model School & College. Explore our classrooms, student support and school activities through this public demonstration.','photo_path'=>'assets/demo-avatar.svg','role_key'=>'principal','sort_order'=>1]);


 for($class=1;$class<=5;$class++)for($part=1;$part<=3;$part++){
  $subject=($class-1)*3+$part;
  demo_insert($pdo,'exam_routines',['session_id'=>1,'class_id'=>$class,'shift_id'=>1,'section_id'=>$class,'term_id'=>1,'part_id'=>1,'subject_id'=>$subject,'exam_date'=>demo_seed_date('Y-m-d',demo_seed_time('+'.($part+7).' days')),'start_time'=>'10:00:00','end_time'=>'12:00:00']);
  for($week=1;$week<=4;$week++)demo_insert($pdo,'elearning_homework',['class_id'=>$class,'section_id'=>$class,'subject_id'=>$subject,'teacher_id'=>($class-1)*3+$part,'title'=>['Bangla reading','English writing','Mathematics practice'][$part-1].' - week '.$week,'description'=>'Fictional sample assignment. Read the lesson, answer the questions and submit your work.','deadline'=>demo_seed_date('Y-m-d H:i:s',demo_seed_time('+'.($week*3).' days'))]);
 }
 for($n=1;$n<=240;$n++)for($part=1;$part<=3;$part++){
  $data=['session_id'=>1,'term_id'=>1,'part_id'=>1,'class_id'=>($n-1)%5+1,'shift_id'=>1,'section_id'=>($n-1)%5+1,'student_id'=>$n,'subject_id'=>((($n-1)%5)*3)+$part];
  demo_insert($pdo,'exam_eligibility',$data+['is_eligible'=>1]);
  demo_insert($pdo,'exam_attendance_subject',$data+['status'=>'Present']);
 }
 foreach(['Bangla literature','Science','Mathematics','History','English language','General knowledge'] as $i=>$category)demo_insert($pdo,'library_categories',['id'=>$i+1,'category_name'=>$category]);
 for($n=1;$n<=60;$n++)demo_insert($pdo,'library_books',['id'=>$n,'book_code'=>'DEMO-B'.sprintf('%03d',$n),'title'=>['Stories from Bangladesh','Discovering science','Mathematics explorer','Our history','Everyday English','World of knowledge'][($n-1)%6].' - volume '.(intdiv($n-1,6)+1),'author'=>'Fictional author '.$n,'publisher'=>'Shapla Demo Publications','category_id'=>($n-1)%6+1,'language'=>$n%3?'Bengali':'English','shelf_no'=>'S'.(($n-1)%6+1),'price'=>150+$n*7,'total_copies'=>5,'available_copies'=>3]);
 for($n=1;$n<=120;$n++)demo_insert($pdo,'library_issues',['book_id'=>($n-1)%60+1,'student_id'=>$n,'issue_date'=>demo_seed_date('Y-m-d',demo_seed_time('-10 days')),'due_date'=>demo_seed_date('Y-m-d',demo_seed_time('+4 days')),'status'=>'Issued','remarks'=>'Fictional public demo loan','issued_by'=>'demo_admin']);
 foreach(['Exercise books','Whiteboard markers','Football','Geometry sets','Science kits','Printer paper','Art supplies','School badges','Library folders','First aid kit','Cricket bats','Desk organizers'] as $i=>$item)demo_insert($pdo,'inventory_items',['name'=>$item,'stock_qty'=>20+$i*9]);
 for($route=1;$route<=3;$route++){
  demo_insert($pdo,'transport_vehicles',['id'=>$route,'vehicle_no'=>'DEMO-BUS-'.$route,'bus_no'=>'S'.$route,'vehicle_name'=>'Shapla demo bus '.$route,'capacity'=>40,'remarks'=>'Fictional vehicle; no live GPS']);
  demo_insert($pdo,'transport_routes',['id'=>$route,'vehicle_id'=>$route,'route_no'=>'R'.$route,'route_name'=>['Rangpur North (fictional)','Rangpur East (fictional)','Rangpur West (fictional)'][$route-1],'monthly_fee'=>1200]);
  for($stop=1;$stop<=5;$stop++)demo_insert($pdo,'transport_stops',['id'=>($route-1)*5+$stop,'route_id'=>$route,'stop_name'=>'Demo neighbourhood '.$route.'-'.$stop,'stop_order'=>$stop,'pickup_time'=>sprintf('07:%02d:00',$stop*5),'drop_time'=>sprintf('14:%02d:00',$stop*5)]);
 }
 for($n=1;$n<=36;$n++){
  $route=($n-1)%3+1;demo_insert($pdo,'transport_student_assign',['student_id'=>$n,'route_id'=>$route,'stop_id'=>($route-1)*5+(($n-1)%5)+1,'assigned_date'=>demo_seed_date('Y-m-d',demo_seed_time('-30 days')),'remarks'=>'Fictional demo assignment']);
  demo_insert($pdo,'transport_fee_invoices',['student_id'=>$n,'route_id'=>$route,'month_year'=>demo_seed_date('Y-m'),'amount'=>1200,'paid_amount'=>$n%3?1200:0,'status'=>$n%3?'Paid':'Unpaid']);
 }
 for($n=1;$n<=12;$n++)demo_insert($pdo,'admissions',['student_name_bn'=>'কাল্পনিক আবেদনকারী '.$n,'student_name_en'=>'Fictional applicant '.$n,'apply_class'=>'Class '.(($n-1)%5+6),'apply_group'=>'Science','dob'=>'2013-05-15','religion'=>'Islam','gender'=>$n%2?'Female':'Male','father_name'=>'Demo guardian '.$n,'mother_name'=>'Demo parent '.$n,'guardian_phone'=>'01XXXXXXXXX','guardian_email'=>'applicant'.$n.'@shapla.example.invalid','address'=>'Fictional demo address, Rangpur','status'=>'Pending']);
 for($n=1;$n<=15;$n++)demo_insert($pdo,'notices',['title'=>['পরীক্ষার প্রস্তুতি নির্দেশনা','অভিভাবক সভার সময়সূচি','পাঠাগারের নতুন বই','ক্রীড়া প্রতিযোগিতা','শিক্ষার্থী ক্লাবের কার্যক্রম'][($n-1)%5].' - ডেমো '.$n,'notice_date'=>demo_seed_date('Y-m-d',demo_seed_time('-'.$n.' days')),'branch_id'=>1,'body'=>'<p>এটি একটি কাল্পনিক ডেমো নোটিশ। তথ্য পরিবর্তন করে স্কুলের ওয়েবসাইটে ফলাফল দেখুন।</p>']);
 for($n=1;$n<=9;$n++)demo_insert($pdo,'website_events',['title'=>['Science discovery day','Reading festival','Sports celebration','Art workshop','Language club','Parent meeting','Math olympiad','School debate','Community learning'][($n-1)%9].' (fictional)','event_date'=>demo_seed_date('Y-m-d',demo_seed_time('+'.($n*3).' days')),'image_path'=>'assets/demo-school.svg','location'=>'Fictional Shapla campus, Rangpur','description'=>'Sample event for the public demo school.','sort_order'=>$n]);
 for($n=1;$n<=8;$n++){
  demo_insert($pdo,'website_news',['title'=>'Shapla school activity '.$n.' (fictional)','image_path'=>'assets/demo-school.svg','short_desc'=>'Students explore creativity, teamwork and learning in this fictional school story.','full_desc'=>'This article is synthetic demo content. It describes a fictional school activity and can be edited in your private demo.','news_date'=>demo_seed_date('Y-m-d',demo_seed_time('-'.$n.' days'))]);
  demo_insert($pdo,'website_albums',['title'=>'School memories '.$n.' (fictional)','cover_image'=>'assets/demo-school.svg','description'=>'Fictional public demo album','sort_order'=>$n]);
  demo_insert($pdo,'gallery_items',['caption'=>'Fictional school activity '.$n,'image_path'=>'assets/demo-school.svg','sort_order'=>$n+4]);
 }
 demo_insert($pdo,'website_useful_links',['title'=>'School ERP demo guide','url'=>'https://www.schoolmng.com/demo/']);
 for($n=1;$n<=12;$n++) {
  $state=$n%4===0?'Rejected':'Pending';
  demo_insert($pdo,'scholarship_applications',['student_id'=>$n,'session_id'=>1,'class_id'=>($n-1)%5+1,'applicant_name'=>'Fictional guardian '.$n,'applicant_relation'=>'Guardian','occupation'=>'Fictional shopkeeper','monthly_income'=>9000+$n*250,'family_members'=>5,'earning_members'=>1,'children_json'=>json_encode([['gender'=>'Male','age'=>'12','inst'=>'Fictional Shapla Model School','class'=>'Demo class']]),'present_address'=>'Fictional ward '.$n.', Rangpur, Bangladesh','permanent_address'=>'Fictional village, Rangpur, Bangladesh','mobile'=>'0170000'.sprintf('%04d',$n),'reason'=>'Fictional education support application for exploring this demo.','status'=>$state,'admin_remarks'=>$state==='Rejected'?'Provide more information and submit a new fictional application.':null]);
 }
 for($class=1;$class<=5;$class++)for($part=1;$part<=3;$part++) {
  $state=['Pending','Approved','Rejected'][$part-1];
  demo_insert($pdo,'teacher_question_papers',['branch_id'=>1,'teacher_id'=>($class-1)*3+$part,'session_id'=>1,'class_id'=>$class,'subject_id'=>($class-1)*3+$part,'title'=>'Fictional sample question - class '.$class.' subject '.$part,'exam_name'=>'Demo practice assessment','full_marks'=>100,'duration_minutes'=>60,'exam_date'=>demo_seed_date('Y-m-d',demo_seed_time('+7 days')),'question_text'=>'Fictional practice questions: 1. Explain the lesson in your own words. 2. Give three examples. 3. Solve the exercise and show your work.','status'=>$state,'submitted_at'=>demo_seed_date('Y-m-d H:i:s'),'admin_remarks'=>$state==='Rejected'?'Add a clear marks distribution before resubmitting.':null,'reviewed_by'=>$state==='Pending'?null:'demo_admin','reviewed_at'=>$state==='Pending'?null:demo_seed_date('Y-m-d H:i:s')]);
 }
 foreach(['Learning support','Creative participation','Attendance achievement'] as $i=>$award)demo_insert($pdo,'scholarship_types',['id'=>$i+1,'name'=>$award.' (demo)','category'=>'School','description'=>'Fictional school award']);
 for($n=1;$n<=18;$n++)demo_insert($pdo,'scholarship_awards',['student_id'=>$n,'scholarship_type_id'=>($n-1)%3+1,'session_id'=>1,'class_id'=>($n-1)%5+1,'amount'=>500+$n*50,'status'=>'Pending','remarks'=>'Fictional demo award','created_by'=>'demo_admin']);
 // Fictional lesson plans expose the approval workflow without touching real schools.
 for($class=1;$class<=5;$class++)for($part=1;$part<=3;$part++){
  $topic=['Bangla: story reading and creative writing','English: describing our school','Mathematics: fractions in everyday life'][$part-1];
  demo_insert($pdo,'elearning_lesson_plans',['branch_id'=>1,'session_id'=>1,'class_id'=>$class,'section_id'=>$class,'subject_id'=>($class-1)*3+$part,'teacher_id'=>($class-1)*3+$part,'title'=>$topic.' (fictional demo)','chapter'=>'Practice lesson '.$class,'lesson_date'=>demo_seed_date('Y-m-d'),'duration_minutes'=>45,'objectives'=>'Students explain the topic in their own words and solve a short practice task.','methods'=>'Discussion, pair work and guided practice.','materials'=>'Textbook, whiteboard and fictional classroom worksheets.','activities'=>'Warm-up: 5 minutes; guided practice: 20 minutes; group activity: 15 minutes; review: 5 minutes.','assessment'=>'Ask three review questions and check a short exit ticket.','homework'=>'Complete five sample exercises. This is fictional demo content.','status'=>'Approved','reviewed_by'=>'demo_admin','reviewed_at'=>demo_seed_date('Y-m-d H:i:s'),'created_by'=>'teacher#'.(($class-1)*3+$part)]);
 }
 foreach(['Draft','Pending','Rejected'] as $status)demo_insert($pdo,'elearning_lesson_plans',['branch_id'=>1,'session_id'=>1,'class_id'=>1,'section_id'=>1,'subject_id'=>1,'teacher_id'=>1,'title'=>'Bangla lesson - '.$status.' (fictional demo)','lesson_date'=>demo_seed_date('Y-m-d',demo_seed_time('+1 day')),'duration_minutes'=>45,'objectives'=>'Practice reading and explaining a short fictional story.','status'=>$status,'submitted_at'=>$status==='Draft'?null:demo_seed_date('Y-m-d H:i:s'),'admin_remarks'=>$status==='Rejected'?'Add a short assessment and resubmit this fictional plan.':null,'created_by'=>'teacher#1']);
 $pdo->commit();

 foreach(['admin','employees','students','system_users'] as $table)$pdo->exec('CREATE TRIGGER '.demo_ident('demo_lock_'.$table).' BEFORE UPDATE ON '.demo_ident($table)." FOR EACH ROW BEGIN IF NOT(NEW.password <=> OLD.password) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Password changes disabled in public demo'; END IF; END");
 require_once __DIR__.'/demo_sync.php';
 try { demo_sync_database($pdo); } catch (Throwable $e) { error_log('Demo sync failed: '.$e->getMessage()); }
}
