<?php

namespace App\Http\Controllers;

use App\Certificate;
use App\CustomClasses\MailgunFacade;
use App\repositories\RepositorieFactory as RepositorieFactory;
use App\Rol;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Session;
use Maatwebsite\Excel\Facades\Excel;
use Psy\Test\Exception\RuntimeExceptionTest;

class UserController extends Controller
{

    private $_userRepository;
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(RepositorieFactory $repositoryFactory)
    {
        $this->middleware('auth');
        //in the update en edit methode we check if the user is editing his own page or he is a administrator
        $this->middleware('authorize:'. Config::get('constants.Administrator'))->except(['edit','update','show']);
        $this->_userRepository = $repositoryFactory->getRepositorie(RepositorieFactory::$USERREPOKEY);
    }

    //gives the user views
    public function index(){
        $users = $this->_userRepository->getCurrentUsers(array('id','firstname','lastname','email','preposition','kind_of_member'));
        $roles = Rol::all();

        return view('beheer.user.index', compact('users','roles'));
    }

    //gives the old users view
    public function indexOldMembers() {
        $users = $this->_userRepository->getOldUsers(array('id','firstname','lastname','email','preposition','kind_of_member'));

        return view('beheer.user.index_old_users', compact('users'));
    }

    //show create screen
    public function create(){
        $fields = ['title' => trans('user.add'),
            'method' => 'POST',
            'url' => '/users',];
        $user = null;
        $roles = Rol::all();
        $ownedRoles = collect();
        return view('beheer.user.create_edit', compact('fields','user','roles', 'ownedRoles'));
    }

    //store user
    public function store(Request $request){
        $this->validateInput($request);

        $user = $this->_userRepository->create($request->all());
        if(Input::get('roles') != null){
            $this->_userRepository->addRols($user->id,Input::get('roles'));
        }

        Session::flash("message",trans('user.added'));

        return redirect('/users');
    }

    public function show(Request $request, User $user){
        if(Auth::user()->id != $user->id && !Auth::user()->hasRole(Config::get('constants.Administrator'))){
            abort(403, trans('validation.Unauthorized'));
        }
        return view('beheer.user.show', compact('user'));
    }

    //show edit screen
    public function edit(Request $request,User $user){
        if(Auth::user()->id != $user->id && !Auth::user()->hasRole(Config::get('constants.Administrator'))){
            abort(403, trans('validation.Unauthorized'));
        }

        $fields = ['title' => trans('user.edit'),
            'method' => 'PATCH',
            'url' => '/users/'. $user->id];

        $roles = Rol::all();
        $ownedRoles = $user->roles;
        return view('beheer.user.create_edit', compact('fields','user','roles', 'ownedRoles'));
    }

    //update user
    public function update(Request $request,User $user, MailgunFacade $mailgunFacade){
        if(!Auth::user()->hasRole(Config::get('constants.Administrator'))){
            if(Auth::user()->id != $user->id || $request->has('kind_of_member')){
                abort(403, trans('validation.Unauthorized'));
            }
        }
        if($user->email != $request['email']){
            //check if email is unique
            $this->validateInput($request);
            $mailgunFacade->updateUserEmailFormAllMailList($user,$user->email,$request['email']);
        }

        $this->_userRepository->update($user->id, $request->all());
        if(Auth::user()->hasRole(Config::get('constants.Administrator'))){
            $this->_userRepository->addRols($user->id,Input::get('roles',[]));
        }

        if(Auth::user()->id === $user->id){
            return redirect('/users/'. $user->id . '?back=false');
        } else{
            Session::flash("message",trans('user.edited'));

            return redirect('/users');
        }
    }

    public function removeAsActiveMember(Request $request, User $user,MailgunFacade $mailgunFacade){
        $user->removeAsActiveMember();
        $mailgunFacade->deleteUserFormAllMailList($user);

        return redirect('/users/'. $user->id);
    }

    public function exportUsers(){
        $activeUsers = $this->_userRepository->getCurrentUsers();
        $exportData = [];
        foreach ($activeUsers as $user){
            $data = $user->toArray();
            $data['certificates'] = $user->getCertificationsAbbreviations();
            array_push($exportData,$data);
        }
        // Generate and return the spreadsheet
        Excel::create(trans('user.members'), function($excel) use ($exportData){
            // Build the spreadsheet, passing in the payments array
            $excel->sheet(trans('user.active_members'), function($sheet) use ($exportData) {

                $sheet->fromArray($exportData,null,'A1');
            });
        })->download('xls');
    }

    private function validateInput(Request $request){
        $this->validate($request,[
            'email' => 'required|email|max:255|unique:users',
            'firstname' => 'required',
            'lastname' => 'required',
            'street' => 'required',
            'houseNumber' => 'required',
            'city' => 'required',
            'zipcode' => 'required',
            'country' => 'required',
            'phonenumber' => 'required',
            'emergencyNumber' => 'required',
            'emergencyHouseNumber' => 'required',
            'emergencystreet' => 'required',
            'emergencycity' => 'required',
            'emergencyzipcode' => 'required',
            'emergencycountry' => 'required',
            'birthDay' => 'required|date',
            'gender' => 'required',
            'IBAN' => 'required'
        ]);

        // These fields are only required for administrators.
        if (Auth::user()->hasRole(Config::get('constants.Administrator'))) {
            $this->validate($request, [
                'kind_of_member' => 'required',
            ]);
        }
    }
}
