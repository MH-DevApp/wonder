<?php

namespace App\Controller;

use App\Entity\ResetPassword;
use App\Entity\User;
use App\Form\UserType;
use App\Repository\ResetPasswordRepository;
use App\Repository\UserRepository;
use App\Security\LoginFormAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Validator\Constraints\DateTime;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class SecurityController extends AbstractController
{
    /**
     * @throws TransportExceptionInterface
     * @throws \Exception
     */
    #[Route("/signup", name: "signup")]
    public function signup(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        UserAuthenticatorInterface $userAuthenticator,
        LoginFormAuthenticator $loginForm,
        MailerInterface $mailer
    )
    {
        $user = new User();
        $userForm = $this->createForm(UserType::class, $user);
        $userForm->handleRequest($request);

        if ($userForm->isSubmitted() && $userForm->isValid()) {
            /** @var File $picture */
            $picture = $userForm->get('pictureFile')->getData();
            $folder = $this->getParameter('profile.folder');
            $ext = $picture->guessExtension() ?? 'bin';
            $filename = bin2hex(random_bytes(10)) . '.' . $ext;
            $picture->move($folder, $filename);
            $user->setPicture($this->getParameter('profile.folder.public_path') . '/' . $filename);

            $hash = $passwordHasher->hashPassword($user, $user->getPassword());
            $user->setPassword($hash);
            $em->persist($user);
            $em->flush();
            $this->addFlash("success", "Bienvenue sur Wonder !");
            $email = new TemplatedEmail();
            $email->to($user->getEmail())
                  ->subject('Bienvenue sur Wonder')
                  ->htmlTemplate('@emails_templates/welcome.html.twig')
                  ->context([
                    'username' => $user->getFirstname()
            ]);
            $mailer->send($email);
            return $userAuthenticator->authenticateUser($user, $loginForm, $request);
        }

        return $this->render("security/signup.html.twig", [
            'form' => $userForm->createView()
        ]);
    }

    #[Route("/login", name: "login")]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error
        ]);
    }


     #[Route("/logout", name: "logout")]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/reset-password/{token}', name: 'reset-password')]
    public function resetPassword(
        RateLimiterFactory $passwordRecoveryLimiter,
        UserPasswordHasherInterface $passwordHasher,
        Request $request,
        ?string $token,
        ResetPasswordRepository $resetPasswordRepo,
        EntityManagerInterface $em
    ): Response
    {
        $limiter = $passwordRecoveryLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Vous devez attendre 60 minutes pour refaire une tentative de récupération de votre mot de passe.');
            return $this->redirectToRoute('login');
        }

        $resetPassword = $resetPasswordRepo->findOneBy(['token' => sha1($token)]);

        if (!$resetPassword || $resetPassword->getExpiredAt() < new DateTime('now')) {
            if ($resetPassword) {
                $em->remove($resetPassword);
                $em->flush();
            }
            $this->addFlash('error', 'La demande est expiré, veuillez renouveler la procédure de récupération du mot de passe.');
            return $this->redirectToRoute('login');
        }

        $passwordForm = $this->createFormBuilder()
            ->add('password', PasswordType::class, [
                'label' => 'Nouveau mot de passe',
                'constraints' => [
                    new Length([
                        'min' => 6,
                        'minMessage' => 'Le mot de passe doit contenir au minimum 6 caractères.'
                    ]),
                    new NotBlank([
                        'message' => 'Veuillez renseigner un mot de passe.'
                    ])
                ]
            ])
            ->getForm();

        $passwordForm->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $password = $passwordForm->get('password')->getData();
            $user = $resetPassword->getUser();
            $hash = $passwordHasher->hashPassword($user, $password);
            $user->setPassword($hash);
            $em->remove($resetPassword);
            $em->flush();
            $this->addFlash('success', 'Votre mot de passe a été modifié avec succès.');
            return $this->redirectToRoute('login');
        }

        return $this->render('security/reset-password-form.html.twig', [
            'form' => $passwordForm->createView(),
        ]);
    }

    /**
     * @throws \Exception
     * @throws TransportExceptionInterface
     */
    #[Route("/reset-password-request", name: "reset-password-request")]
    public function resetPasswordRequest(
        RateLimiterFactory $passwordRecoveryLimiter,
        Request $request,
        UserRepository $userRepo,
        ResetPasswordRepository $resetPasswordRepo,
        EntityManagerInterface $em,
        MailerInterface $mailer,
    ): Response
    {
        $limiter = $passwordRecoveryLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Vous devez attendre 60 minutes pour refaire une tentative de récupération de votre mot de passe.');
            return $this->redirectToRoute('login');
        }

        $emailForm = $this->createFormBuilder()->add('email', EmailType::class, [
            'constraints' => [
                new NotBlank([
                    'message' => 'Veuillez renseigner votre email'
                ])
            ]
        ])->getForm();

        $emailForm->handleRequest($request);

        if ($emailForm->isSubmitted() && $emailForm->isValid())
        {
            $email = $emailForm->get('email')->getData();
            $user = $userRepo->findOneBy(['email' => $email]);
            if ($user) {
                $oldResetPassword = $resetPasswordRepo->findOneBy(['user' => $user]);
                if ($oldResetPassword) {
                    $em->remove($oldResetPassword);
                    $em->flush();
                }
                $resetPassword = new ResetPassword();
                $resetPassword->setUser($user);
                $resetPassword->setExpiredAt(new \DateTimeImmutable('+2 hours'));
                $token = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(30))), 0, 20);
                $resetPassword->setToken(sha1($token));
                $em->persist($resetPassword);
                $em->flush();

                $emailTemplate = new TemplatedEmail();
                $emailTemplate->to($email)
                              ->subject('Demande de réinitialisation de mot de passe')
                              ->htmlTemplate('@emails_templates/reset-password-request.html.twig')
                              ->context([
                                  'token' => $token
                              ]);
                $mailer->send($emailTemplate);
            }
            $this->addFlash('success', 'Un email vous a été envoyé pour réinitialiser votre mot de passe.');
            return $this->redirectToRoute('home');
        }

        return $this->render('security/reset-password-request.html.twig', [
            'form' => $emailForm->createView()
        ]);
    }
}
