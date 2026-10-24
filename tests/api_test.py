"""Run against a disposable database and a running PHP server. No third-party packages."""
import http.cookiejar, json, os, time, unittest, urllib.request, urllib.error, uuid
BASE = os.getenv('RIMBO_TEST_URL', 'http://127.0.0.1:8080/api/index.php')
class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ''
    def request(self, route, method='GET', data=None, csrf=True):
        request = urllib.request.Request(BASE+'?route='+route, data=None if data is None else json.dumps(data).encode(), method=method, headers={'Content-Type':'application/json', **({'X-CSRF-Token':self.csrf} if csrf else {})})
        try: response=self.http.open(request)
        except urllib.error.HTTPError as e: response=e
        body=json.loads(response.read())
        if body.get('csrf'): self.csrf=body['csrf']
        return response.status,body
    def register(self, email):
        assert self.request('session')[0]==200
        return self.request('register','POST',{'name':'Test user','email':email,'password':'testing-strong-password-123'})
class APITest(unittest.TestCase):
    def test_full_account_lifecycle(self):
        suffix=uuid.uuid4().hex
        a,b,guest=Client(),Client(),Client()
        self.assertEqual(guest.request('workspace')[0],401)
        self.assertEqual(a.register(suffix+'a@example.test')[0],201)
        self.assertEqual(b.register(suffix+'b@example.test')[0],201)
        cookies=list(a.jar)
        self.assertTrue(cookies)
        self.assertIn('HttpOnly',cookies[0]._rest)
        self.assertEqual(cookies[0]._rest.get('SameSite'),'Lax')
        status,result=a.request('workspace')
        self.assertEqual(status,200)
        self.assertEqual(result['state']['ideas'],[])
        state=result['state']
        state['ideas']=[{'id':'idea-one','title':'Customer discovery','notes':'Interview three homeowners','category':'Business','stage':'Exploring','source':'text','links':[], 'attachments':[{'id':'file-one','name':'notes.txt','type':'text/plain','data':'data:text/plain;base64,aGVsbG8='}], 'history':[{'at':1700000000000,'text':'First observation'}], 'reminder':'2030-01-01T09:00:00.000Z','repeat':'weekly'}]
        self.assertEqual(a.request('workspace','PUT',{'revision':0,'state':state},csrf=False)[0],403)
        self.assertEqual(a.request('workspace','PUT',{'revision':0,'state':state})[0],200)
        self.assertEqual(a.request('workspace')[1]['state']['ideas'][0]['attachments'][0]['data'],'data:text/plain;base64,aGVsbG8=')
        self.assertEqual(b.request('workspace')[1]['state']['ideas'],[])
        # A stale writer cannot replace another device's update.
        self.assertEqual(a.request('workspace','PUT',{'revision':0,'state':state})[0],409)
        self.assertEqual(a.request('workspace')[1]['revision'],1)
        # Malformed IDs cannot enter inline frontend handlers.
        state['ideas'][0]['id']="bad');alert(1)//"
        self.assertEqual(a.request('workspace','PUT',{'revision':1,'state':state})[0],422)
        state['ideas'][0]['id']='idea-one'
        state['ideas'][0]['attachments'][0]['data']='data:text/html;base64,PHNjcmlwdD4='
        self.assertEqual(a.request('workspace','PUT',{'revision':1,'state':state})[0],422)
        state['ideas'][0]['attachments']=[]
        # Trash, restore, and connections persist.
        state['ideas'][0]['deleted']=True
        self.assertEqual(a.request('workspace','PUT',{'revision':1,'state':state})[0],200)
        self.assertTrue(a.request('workspace')[1]['state']['ideas'][0]['deleted'])
        state['ideas'][0]['deleted']=False
        state['ideas'].append({'id':'idea-two','title':'Pricing','notes':'Plan packages','category':'Business','stage':'Captured','source':'text','links':['idea-one'],'attachments':[]})
        state['ideas'][0]['links']=['idea-two']
        self.assertEqual(a.request('workspace','PUT',{'revision':2,'state':state})[0],200)
        self.assertEqual(a.request('workspace')[1]['state']['ideas'][1]['links'],['idea-one'])
        self.assertEqual(a.request('logout','POST',{})[0],200)
        self.assertEqual(a.request('workspace')[0],401)
        self.assertEqual(a.request('login','POST',{'email':suffix+'a@example.test','password':'incorrect'})[0],401)
        self.assertEqual(a.request('login','POST',{'email':suffix+'a@example.test','password':'testing-strong-password-123'})[0],200)
        self.assertEqual(a.request('workspace')[1]['revision'],3)
        self.assertEqual(len(a.request('workspace')[1]['state']['ideas']),2)
        # Same idea IDs in separate accounts are allowed and isolated.
        other=b.request('workspace')[1]
        other['state']['ideas']=[{'id':'idea-one','title':'Private B','notes':'Only B','category':'Personal','stage':'Captured'}]
        self.assertEqual(b.request('workspace','PUT',other)[0],200)
        self.assertEqual(a.request('workspace')[1]['state']['ideas'][0]['title'],'Customer discovery')
    def test_registration_validation_and_throttle(self):
        c=Client();c.request('session')
        email=uuid.uuid4().hex+'@example.test'
        self.assertEqual(c.request('register','POST',{'name':'A','email':email,'password':'short'})[0],422)
        for _ in range(14):
            self.assertEqual(c.request('login','POST',{'email':email,'password':'incorrect'})[0],401)
        self.assertEqual(c.request('login','POST',{'email':email,'password':'incorrect'})[0],429)
if __name__=='__main__': unittest.main()
