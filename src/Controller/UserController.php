<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class UserController extends AbstractController
{
    #[Route('/user/{id}', name: 'user')]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function userProfile(?User $user): Response
    {
        if (!$user) {
            throw new NotFoundHttpException('Page non trouvée');
        }
        $currentUser = $this->getUser();
        if ($currentUser === $user) {
            return $this->redirectToRoute('current_user');
        }
        return $this->render('user/show.html.twig', [
            'user' => $user,
        ]);
    }
    #[Route('/profile', name: 'current_user')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function currentUserProfile(
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        Request $request
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userForm = $this->createForm(UserType::class, $user);
        $userForm->remove('password');
        $userForm->add('newPassword', PasswordType::class, ['label' => 'Nouveau mot de passe', 'required' => false]);
        $userForm->handleRequest($request);

        if ($userForm->isSubmitted() && $userForm->isValid()) {
            $newPassword = $user->getNewPassword();
            if ($newPassword) {
                $hash = $passwordHasher->hashPassword($user, $newPassword);
                $user->setPassword($hash);
            }
            $em->flush();
            $this->addFlash('success', 'Votre profil a été mis à jour !');
        }

        return $this->render('user/index.html.twig', [
            'form' => $userForm->createView(),
        ]);
    }
}
